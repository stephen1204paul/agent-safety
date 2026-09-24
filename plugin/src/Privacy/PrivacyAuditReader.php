<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Privacy;

use wpdb;

/**
 * Read side of the audit log scoped to ONE WordPress user, for the
 * personal-data exporter and eraser (§3.6 item 2). Matches ONLY the `wp_user`
 * column {@see \Specflux\AgentSafety\Plugin\Audit\WpdbAuditSink} writes
 * alongside `record_json` (mirroring the audit record's `actor.wp_user`) —
 * never `input` or any other field — so a tool argument that happens to
 * contain someone else's email can never leak their audit rows into an
 * export or an erasure report.
 */
final class PrivacyAuditReader
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . 'agsafe_audit_log';
    }

    /** Total rows for this user, across every page. */
    public function countForUser(int $userId): int
    {
        $table = $this->table();

        return (int) $this->db->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare("SELECT COUNT(*) FROM {$table} WHERE wp_user = %d", $userId)
        );
    }

    /**
     * One page of a user's rows, oldest-first (a stable order across a paged
     * export or erasure request).
     *
     * @return list<array<string, mixed>>
     */
    public function pageForUser(int $userId, int $limit, int $offset): array
    {
        $table = $this->table();
        $rows = $this->db->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare(
                "SELECT * FROM {$table} WHERE wp_user = %d ORDER BY id LIMIT %d OFFSET %d",
                $userId,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }
}
