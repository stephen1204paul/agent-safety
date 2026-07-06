<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Audit;

use Specflux\AgentSafety\Approval\ApprovalStore;
use wpdb;

/**
 * Approval store (SPEC §4) backed by a custom table. Implements the core
 * {@see ApprovalStore} seam plus the host-only read/mutate methods the wp-admin
 * "Pending Agent Actions" screen needs.
 *
 * Security / correctness properties:
 *  - The bearer token is shown to the approver ONCE and never persisted in the
 *    clear — only its SHA-256 hash is stored (tokens-not-PANs, D14). A DB read
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
final class WpdbApprovalStore implements ApprovalStore
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
        return $this->db->prefix . 'agsafe_approvals';
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

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                approval_id VARCHAR(64) NOT NULL,
                verb VARCHAR(191) NOT NULL,
                args_hash CHAR(64) NOT NULL,
                summary TEXT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                audit_event_id VARCHAR(64) NULL,
                key_id VARCHAR(64) NULL,
                status VARCHAR(20) NOT NULL,
                token_hash CHAR(64) NULL,
                approver BIGINT NULL,
                reserved_req VARCHAR(64) NULL,
                reserved_ts DATETIME NULL,
                created_ts DATETIME NOT NULL,
                pending_expires_ts DATETIME NULL,
                expires_ts DATETIME NULL,
                consumed_ts DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY approval_id (approval_id),
                KEY status (status),
                KEY verb_args (verb, args_hash),
                KEY ref (key_id, verb, args_hash),
                KEY token_hash (token_hash)
            ) {$charset}"
        );

        $this->ensured = true;
    }
}
