<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Approval;

use Specflux\AgentSafety\Approval\Grant;
use Specflux\AgentSafety\Approval\GrantStatus;
use Specflux\AgentSafety\Approval\GrantStore;
use Specflux\AgentSafety\Plugin\Support\Schema;
use wpdb;

/**
 * Pre-approval grant store backed by its own custom table (shape owned by
 * {@see Schema::grantsColumns()}). Implements the core {@see GrantStore} seam
 * plus the host-only counts the {@see \Specflux\AgentSafety\Plugin\Api\Grants}
 * service audits with.
 *
 * Deliberately NOT rows in the approvals table and NOT transients: a grant
 * outlives a request, must survive an object-cache flush, and its lifecycle
 * (count down, release, revoke, expire) is nothing like an approval's
 * bind-to-one-exact-action lifecycle.
 *
 * Correctness properties:
 *  - {@see reserve()} decides with the CORE rule ({@see Grant::canReserve()}) and
 *    then claims with a conditional UPDATE that repeats the same conditions in
 *    SQL. The pure rule is the contract (and is unit-tested without a database);
 *    the SQL guard is there for atomicity — two concurrent calls can decrement
 *    one grant at most once each, and can never take it below zero.
 *  - An empty or absent subject NEVER matches, mirroring
 *    {@see \Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore::reserve()}. That
 *    check happens before any query is built.
 *  - The hard TTL is a wall independent of the count
 *    (filter `agent_safety_grant_ttl`, default 24 h) and every comparison is in
 *    UTC (UTC_TIMESTAMP()), so no server-timezone drift can extend a grant.
 *  - {@see release()} can restore an `exhausted` grant to `active` but can never
 *    resurrect a revoked or expired one.
 */
final class WpdbGrantStore implements GrantStore
{
    /**
     * Hard grant lifetime. After this a grant is dead however much count it has
     * left. Filterable via `agent_safety_grant_ttl` (seconds); a filtered value
     * that is not a positive int is ignored, and the filter can only ever be
     * applied at ISSUE time, so shortening it later cannot retro-extend a grant
     * already written.
     */
    public const TTL_SECONDS = 86400; // 24 hours

    private bool $ensured = false;

    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return Schema::grantsTable($this->db);
    }

    public function issue(
        string $verb,
        int $count,
        ?string $subject,
        string $correlationId,
        ?int $grantedBy,
        ?string $planStepId,
    ): string {
        $this->ensureTable();

        $now = time();
        $grantId = 'gnt_' . $this->uuid();

        $this->db->insert(
            $this->table(),
            [
                'grant_id' => $grantId,
                'correlation_id' => $correlationId,
                'verb' => $verb,
                // Never below zero: a caller that asks for 0 or a negative count
                // gets a grant that can authorise nothing, not one that wraps.
                'remaining_count' => max(0, $count),
                'subject' => $subject,
                'granted_by' => $grantedBy,
                'plan_step_id' => $planStepId,
                'status' => GrantStatus::Active->value,
                'created_ts' => gmdate('Y-m-d H:i:s', $now),
                'expires_ts' => gmdate('Y-m-d H:i:s', $now + $this->ttlSeconds()),
            ],
            ['%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s'],
        );

        return $grantId;
    }

    public function reserve(string $correlationId, string $verb, ?string $subject): ?string
    {
        // "Empty subject never matches" — checked BEFORE any SQL is built, so
        // an unauthenticated caller cannot even probe for a grant.
        if ($subject === null || $subject === '' || $correlationId === '') {
            return null;
        }

        $this->ensureTable();

        $candidate = $this->candidate($correlationId, $verb, $subject);
        if ($candidate === null || !$candidate->canReserve($correlationId, $verb, $subject, gmdate('Y-m-d H:i:s'))) {
            return null;
        }

        // Claim that exact row. The WHERE repeats every condition the core rule
        // just checked so a concurrent reserve between the SELECT and here loses
        // the race outright (affected = 0) rather than double-spending.
        //
        // THE SET ORDER IS LOAD-BEARING. MySQL evaluates a multi-column SET left
        // to right, and a later expression sees the value an earlier assignment
        // has ALREADY written. With `remaining_count` assigned first, the CASE
        // read the decremented value and computed `remaining_count - 1` a second
        // time, so a grant of 2 was sealed `exhausted` after its FIRST
        // reservation — the human's second pre-approved call then parked.
        // Assigning `status` first is what makes the CASE see the count as it
        // stood before this claim.
        $affected = $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "UPDATE {$this->table()}
                    SET status = CASE WHEN remaining_count - 1 <= 0 THEN 'exhausted' ELSE 'active' END,
                        remaining_count = remaining_count - 1
                  WHERE grant_id = %s AND status = 'active' AND remaining_count > 0
                    AND revoked_ts IS NULL AND expires_ts > UTC_TIMESTAMP()",
                $candidate->grantId
            )
        );

        return $affected === 1 ? $candidate->grantId : null;
    }

    public function release(string $grantId): void
    {
        $this->ensureTable();

        // Restores an exhausted grant to active, but never a revoked or expired
        // one: a rollback gives back what an execution did not spend, it does not
        // reopen a decision a human (or the clock) already closed.
        $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "UPDATE {$this->table()}
                    SET remaining_count = remaining_count + 1, status = 'active'
                  WHERE grant_id = %s AND status IN ('active', 'exhausted')
                    AND revoked_ts IS NULL AND expires_ts > UTC_TIMESTAMP()",
                $grantId
            )
        );
    }

    public function revokeByCorrelation(string $correlationId): void
    {
        $this->revokeAll($correlationId);
    }

    /**
     * {@see revokeByCorrelation()}, reporting how many grants this call actually
     * withdrew — what the audit event records. Already-revoked rows are left
     * alone so a second revoke (every terminal path calls it) is a no-op that
     * neither re-audits nor rewrites the original revocation timestamp.
     */
    public function revokeAll(string $correlationId): int
    {
        if ($correlationId === '') {
            return 0;
        }

        $this->ensureTable();

        $affected = $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "UPDATE {$this->table()} SET status = 'revoked', revoked_ts = UTC_TIMESTAMP()
                  WHERE correlation_id = %s AND status IN ('active', 'exhausted')",
                $correlationId
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    public function get(string $grantId): ?Grant
    {
        if ($grantId === '') {
            return null;
        }

        $this->ensureTable();

        $row = $this->db->get_row(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "SELECT * FROM {$this->table()} WHERE grant_id = %s LIMIT 1",
                $grantId
            ),
            ARRAY_A
        );

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Every grant in one correlation scope, newest first — a read for the host's
     * own reporting (which grants did this run get?). Never used by the gate.
     *
     * @return list<Grant>
     */
    public function forCorrelation(string $correlationId): array
    {
        if ($correlationId === '') {
            return [];
        }

        $this->ensureTable();

        $rows = $this->db->get_results(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "SELECT * FROM {$this->table()} WHERE correlation_id = %s ORDER BY id DESC",
                $correlationId
            ),
            ARRAY_A
        );

        return array_values(array_map([self::class, 'hydrate'], is_array($rows) ? $rows : []));
    }

    /**
     * Delete grants that can never authorise anything again — the backlog
     * control the hourly approval sweep already does for approvals. Revoked and
     * exhausted rows are KEPT: they are the record of a decision the audit trail
     * cross-references.
     */
    public function deleteExpired(string $nowUtc): int
    {
        $this->ensureTable();

        $affected = $this->db->query(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "DELETE FROM {$this->table()} WHERE status = 'active' AND expires_ts <= %s",
                $nowUtc
            )
        );

        return is_int($affected) ? $affected : 0;
    }

    /**
     * The single best candidate row for (correlation, verb, subject): the one
     * expiring SOONEST, so a run burns its most perishable budget first and a
     * longer-lived grant is not stranded behind it.
     */
    private function candidate(string $correlationId, string $verb, string $subject): ?Grant
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
                "SELECT * FROM {$this->table()}
                  WHERE correlation_id = %s AND verb = %s AND subject = %s
                    AND status = 'active' AND remaining_count > 0
                    AND revoked_ts IS NULL AND expires_ts > UTC_TIMESTAMP()
                  ORDER BY expires_ts ASC, id ASC LIMIT 1",
                $correlationId,
                $verb,
                $subject
            ),
            ARRAY_A
        );

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Row → {@see Grant}. An unrecognised status degrades to `revoked` (the most
     * restrictive value), so schema drift or a hand-edited row fails closed
     * rather than being read as active.
     *
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): Grant
    {
        $str = static fn (mixed $v): string => is_string($v) ? $v : '';
        $nullableStr = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : null;

        return new Grant(
            grantId: $str($row['grant_id'] ?? null),
            correlationId: $str($row['correlation_id'] ?? null),
            verb: $str($row['verb'] ?? null),
            remainingCount: isset($row['remaining_count']) && is_numeric($row['remaining_count'])
                ? (int) $row['remaining_count']
                : 0,
            subject: $nullableStr($row['subject'] ?? null),
            grantedBy: isset($row['granted_by']) && is_numeric($row['granted_by']) ? (int) $row['granted_by'] : null,
            planStepId: $nullableStr($row['plan_step_id'] ?? null),
            createdTs: $str($row['created_ts'] ?? null),
            expiresTs: $str($row['expires_ts'] ?? null),
            revokedTs: $nullableStr($row['revoked_ts'] ?? null),
            status: GrantStatus::tryFrom($str($row['status'] ?? null)) ?? GrantStatus::Revoked,
        );
    }

    /** The hard TTL in seconds; a filtered value that is not a positive int is ignored. */
    private function ttlSeconds(): int
    {
        if (!function_exists('apply_filters')) {
            return self::TTL_SECONDS;
        }

        /** @var mixed $filtered */
        $filtered = apply_filters('agent_safety_grant_ttl', self::TTL_SECONDS);

        return is_int($filtered) && $filtered > 0 ? $filtered : self::TTL_SECONDS;
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

        // Safety net only (same posture as the other two stores): the table is
        // normally created/upgraded at activation by Schema::install(), and this
        // is built from the SAME column definitions so the two paths cannot drift.
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$table} (\n" . Schema::grantsColumns() . "\n) {$charset}"
        );

        $this->ensured = true;
    }
}
