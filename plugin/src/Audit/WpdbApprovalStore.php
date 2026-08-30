<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Audit;

use Specflux\AgentSafety\Approval\ApprovalStore;
use Specflux\AgentSafety\Plugin\Approval\ApprovalMinter;
use Specflux\AgentSafety\Plugin\Support\Schema;
use Specflux\AgentSafety\Plugin\Support\SummaryMarkup;
use wpdb;

/**
 * Approval store backed by a custom table (shape owned by
 * {@see Schema}). Implements the core {@see ApprovalStore} seam plus the
 * host-only read/mutate methods the wp-admin "Pending Agent Actions" screen
 * and the {@see deleteExpired()} cron sweep need.
 *
 * Security / correctness properties:
 *  - The bearer token is shown to the approver ONCE and never persisted in the
 *    clear — only its SHA-256 hash is stored (tokens, not PANs). A DB read
 *    cannot recover a live token.
 *  - {@see reserve()} is single-claim and atomic: a conditional UPDATE flips one
 *    `approved` row to `in_flight`, so two concurrent retries can claim a grant at
 *    most once — an approval can never drive two executions of an irreversible verb.
 *  - The reserve→{@see finalize()}/{@see rollback()} split spends a grant only when
 *    the action actually executed (finalize). If execution fails/aborts the grant is
 *    released (rollback) and a retry within the original TTL can reuse it.
 *  - Pending requests carry their own TTL ({@see PENDING_TTL_SECONDS}); a stale
 *    request is swept to `expired` and can no longer be approved into a live grant.
 *  - All timestamps are stored and compared in UTC (UTC_TIMESTAMP()) to avoid
 *    server-timezone drift in the TTL checks.
 */
final class WpdbApprovalStore implements ApprovalStore, ApprovalMinter
{
    /** Approved-grant lifetime. After this the grant is dead even if unused. */
    private const TTL_SECONDS = 900; // 15 minutes

    /** Pending-request lifetime. A request a human never acts on expires (item 3). */
    private const PENDING_TTL_SECONDS = 3600; // 1 hour

    private bool $ensured = false;

    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return Schema::approvalsTable($this->db);
    }

    public function request(
        string $verb,
        string $argsHash,
        string $summary,
        string $correlationId,
        string $auditEventId,
        ?string $subject,
    ): string {
        $this->ensureTable();
        $table = $this->table();

        // Idempotent per (verb, args_hash, subject) while a NON-EXPIRED pending row
        // exists, so a retrying agent does not pile up duplicate approvals for one
        // human to clear. Scoped by subject so two principals get distinct grants.
        $keyClause = $subject === null ? 'key_id IS NULL' : 'key_id = %s';
        $sql = "SELECT approval_id FROM {$table}
                 WHERE verb = %s AND args_hash = %s AND status = 'pending'
                   AND pending_expires_ts > UTC_TIMESTAMP() AND {$keyClause}
                 ORDER BY id DESC LIMIT 1";
        $params = $subject === null ? [$verb, $argsHash] : [$verb, $argsHash, $subject];
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name + literal key clause.
        $existing = $this->db->get_var($this->db->prepare($sql, ...$params));
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $approvalId = 'apr_' . $this->uuid();
        $this->db->insert(
            $table,
            [
                'approval_id' => $approvalId,
                'verb' => $verb,
                'args_hash' => $argsHash,
                'summary' => $summary,
                'correlation_id' => $correlationId,
                'audit_event_id' => $auditEventId,
                'key_id' => $subject,
                'status' => 'pending',
                'created_ts' => gmdate('Y-m-d H:i:s'),
                'pending_expires_ts' => gmdate('Y-m-d H:i:s', time() + self::PENDING_TTL_SECONDS),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );

        // Fires only for a genuinely NEW pending approval — the idempotent
        // reuse path above returns early, so a retrying agent hammering the
        // same blocked call never re-notifies the humans who must clear it.
        // Consumed by Support\ApprovalNotifier (email + webhook) and open to
        // any site code that wants its own routing. Subscribers get the summary
        // as authored, without the host-authored provenance tag the approval
        // SCREEN uses to decide escaping ({@see SummaryMarkup}) — that tag is a
        // rendering concern and never part of this hook's contract.
        do_action('agent_safety_approval_requested', $approvalId, $verb, SummaryMarkup::unwrap($summary));

        return $approvalId;
    }

    /**
     * Grant a pending request: time-bound it and mint a single-use bearer token.
     * Returns the plaintext token (to show the approver ONCE) or null if the request
     * is missing, not pending, or already past its pending TTL. After this the same
     * record can be claimed either by the minted token (delegation) or by-reference
     * (the original principal simply retries) — see {@see reserve()}.
     */
    public function approve(string $approvalId, int $approver): ?string
    {
        $this->ensureTable();
        $token = 'apt_' . bin2hex(random_bytes(24));

        $affected = $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "UPDATE {$this->table()}
                    SET status = 'approved', token_hash = %s, approver = %d,
                        expires_ts = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND)
                  WHERE approval_id = %s AND status = 'pending'
                    AND pending_expires_ts > UTC_TIMESTAMP()",
                hash('sha256', $token),
                $approver,
                self::TTL_SECONDS,
                $approvalId
            )
        );

        return $affected === 1 ? $token : null;
    }

    /**
     * {@see ApprovalMinter::mintApproved()} — write a row that is ALREADY
     * `approved`, on the strength of a pre-approval grant the same human issued
     * earlier (AS-12).
     *
     * Three deliberate differences from {@see approve()}:
     *  - no bearer token is minted (`token_hash` stays NULL), so the record can
     *    only ever be claimed BY REFERENCE by the exact principal the grant was
     *    issued to. There is no human here to show a token to, and an
     *    unreceived credential is one nobody can revoke.
     *  - it is bound to the REAL call arguments via $argsHash exactly like any
     *    other approval, so the grant's blast radius is still one exact action.
     *  - `approver` is the GRANTOR and `grant_id` records which grant authorised
     *    it, so the audit trail can say "auto-approved under plan grant by X".
     *
     * The grant window, not the pre-approval's own lifetime, is what bounds this
     * record: it carries the same short {@see TTL_SECONDS} as a freshly approved
     * request, because it exists only to be reserved by the call happening RIGHT
     * NOW. A leftover row is therefore dead within minutes even if the call never
     * comes back to claim it.
     */
    public function mintApproved(
        string $verb,
        string $argsHash,
        string $summary,
        string $correlationId,
        string $auditEventId,
        ?string $subject,
        ?int $approver,
        ?string $grantId,
    ): ?string {
        $this->ensureTable();

        $approvalId = 'apr_' . $this->uuid();
        $now = time();

        $inserted = $this->db->insert(
            $this->table(),
            [
                'approval_id' => $approvalId,
                'verb' => $verb,
                'args_hash' => $argsHash,
                'summary' => $summary,
                'correlation_id' => $correlationId,
                'audit_event_id' => $auditEventId,
                'key_id' => $subject,
                'status' => 'approved',
                'approver' => $approver,
                'grant_id' => $grantId,
                'created_ts' => gmdate('Y-m-d H:i:s', $now),
                'expires_ts' => gmdate('Y-m-d H:i:s', $now + self::TTL_SECONDS),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'],
        );

        return $inserted === 1 ? $approvalId : null;
    }

    public function reject(string $approvalId, int $approver): bool
    {
        $this->ensureTable();

        $affected = $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "UPDATE {$this->table()} SET status = 'rejected', approver = %d
                  WHERE approval_id = %s AND status = 'pending'",
                $approver,
                $approvalId
            )
        );

        return $affected === 1;
    }

    public function peekApproved(?string $token, string $verb, string $argsHash, ?string $subject): bool
    {
        $this->ensureTable();

        // Same match rule as reserve(), but a pure read — nothing is claimed.
        if ($token !== null && $token !== '') {
            $matchCol = 'token_hash';
            $matchVal = hash('sha256', $token);
        } elseif ($subject !== null && $subject !== '') {
            $matchCol = 'key_id';
            $matchVal = $subject;
        } else {
            return false;
        }

        $found = $this->db->get_var(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name + literal match column.
                "SELECT approval_id FROM {$this->table()}
                  WHERE {$matchCol} = %s AND verb = %s AND args_hash = %s
                    AND status = 'approved' AND expires_ts > UTC_TIMESTAMP()
                  ORDER BY id DESC LIMIT 1",
                $matchVal,
                $verb,
                $argsHash
            )
        );

        return is_string($found) && $found !== '';
    }

    public function reserve(?string $token, string $verb, string $argsHash, ?string $subject): ?string
    {
        $this->ensureTable();

        // Pick the match column: bearer token (delegation) takes precedence; else the
        // requesting principal (by-reference). Both are literals, never user-built SQL.
        if ($token !== null && $token !== '') {
            $matchCol = 'token_hash';
            $matchVal = hash('sha256', $token);
        } elseif ($subject !== null && $subject !== '') {
            $matchCol = 'key_id';
            $matchVal = $subject;
        } else {
            return null;
        }

        // Atomically claim ONE approved, unexpired grant for this exact verb+args.
        // A per-call nonce lets us read back WHICH row we claimed, race-free.
        $nonce = bin2hex(random_bytes(16));
        $affected = $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name + literal match column.
                "UPDATE {$this->table()}
                    SET status = 'in_flight', reserved_req = %s, reserved_ts = UTC_TIMESTAMP()
                  WHERE {$matchCol} = %s AND verb = %s AND args_hash = %s
                    AND status = 'approved' AND expires_ts > UTC_TIMESTAMP()
                  ORDER BY id DESC LIMIT 1",
                $nonce,
                $matchVal,
                $verb,
                $argsHash
            )
        );
        if ($affected !== 1) {
            return null;
        }

        $id = $this->db->get_var(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "SELECT approval_id FROM {$this->table()} WHERE reserved_req = %s LIMIT 1",
                $nonce
            )
        );

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function finalize(string $approvalId): void
    {
        $this->ensureTable();
        $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "UPDATE {$this->table()} SET status = 'consumed', consumed_ts = UTC_TIMESTAMP()
                  WHERE approval_id = %s AND status = 'in_flight'",
                $approvalId
            )
        );
    }

    public function rollback(string $approvalId): void
    {
        $this->ensureTable();
        // Release back to 'approved' WITHOUT touching expires_ts: a failed action's
        // retry must still happen inside the original grant window (fail-safe).
        $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "UPDATE {$this->table()} SET status = 'approved', reserved_req = NULL, reserved_ts = NULL
                  WHERE approval_id = %s AND status = 'in_flight'",
                $approvalId
            )
        );
    }

    /**
     * Open requests awaiting a human, newest first. Sweeps stale pending requests to
     * `expired` first, so a request a human never got to drops off the actionable
     * list and can no longer be approved.
     *
     * @return list<array<string, mixed>>
     */
    public function pending(): array
    {
        $this->ensureTable();
        $this->expireStale();
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        $rows = $this->db->get_results("SELECT * FROM {$this->table()} WHERE status = 'pending' ORDER BY id DESC", ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed>|null */
    public function get(string $approvalId): ?array
    {
        $this->ensureTable();
        $row = $this->db->get_row(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "SELECT * FROM {$this->table()} WHERE approval_id = %s",
                $approvalId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Delete rows that are both past their TTL and can never be acted on again
     * (the backlog {@see \Specflux\AgentSafety\Plugin\Support\ApprovalSweep}
     * hourly cron exists to control). Called with the current UTC time.
     *
     * Deleted — dead ends with no decision left to preserve:
     *  - `pending` whose {@see PENDING_TTL_SECONDS} window has lapsed: nobody
     *    ever reviewed it. Covers rows {@see expireStale()} hasn't (yet) flipped
     *    to `expired`, so this sweep does not depend on that method having run.
     *  - `expired`: already-flipped stale pending requests.
     *  - `approved` whose {@see TTL_SECONDS} grant window has lapsed WITHOUT
     *    being reserved: a human said yes but the request was never redeemed
     *    into an execution, so the grant is now permanently unusable.
     *
     * Kept — still actionable, or the operational anchor for a real decision
     * already immutably recorded in the hash-chained audit log via
     * {@see \Specflux\AgentSafety\Plugin\Admin\PendingActionsPage::reconcile()}
     * (approve/reject) or {@see \Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate::onExecuted()}
     * (finalize on execution):
     *  - `pending` still within its TTL — a human may yet approve/reject it.
     *  - `in_flight` — an execution is claiming this grant RIGHT NOW; its
     *    lifecycle belongs solely to finalize()/rollback(), never to a sweep,
     *    no matter how long reserved_ts makes it look.
     *  - `rejected` — the row IS the record of who rejected what.
     *  - `consumed` — the row IS the record of which grant authorised which
     *    execution (cross-referenced by the audit trail's `approval.id`).
     */
    public function deleteExpired(string $nowUtc): int
    {
        $this->ensureTable();

        $affected = $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "DELETE FROM {$this->table()}
                  WHERE (status = 'pending' AND pending_expires_ts <= %s)
                     OR status = 'expired'
                     OR (status = 'approved' AND expires_ts <= %s)",
                $nowUtc,
                $nowUtc
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    /** Flip pending requests past their TTL to `expired` (item 3). */
    private function expireStale(): void
    {
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name, no user input.
        $this->db->query(
            "UPDATE {$this->table()} SET status = 'expired'
              WHERE status = 'pending' AND pending_expires_ts <= UTC_TIMESTAMP()"
        );
    }

    private function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return bin2hex(random_bytes(16));
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $table = $this->table();
        $charset = $this->db->get_charset_collate();

        // Safety net only: the table is normally created/upgraded at activation by
        // Schema::install(). Built from the SAME column definitions so the two
        // paths can never disagree on shape.
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$table} (\n" . Schema::approvalsColumns() . "\n) {$charset}"
        );

        $this->ensured = true;
    }
}
