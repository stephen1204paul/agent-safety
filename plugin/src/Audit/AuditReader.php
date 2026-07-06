<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Audit;

use Specflux\AgentSafety\Audit\HashChain;
use wpdb;

/**
 * Read side of the audit log: paged listing, totals, full export, and a chain
 * integrity check that runs the stored rows back through {@see HashChain::verify()}.
 * Read-only — never mutates the append-only table.
 */
final class AuditReader
{
    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . 'agsafe_audit_log';
    }

    public function total(): int
    {
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }

    /**
     * Newest-first page for the admin table.
     *
     * @return list<array<string, mixed>>
     */
    public function latest(int $limit, int $offset = 0): array
    {
        $table = $this->table();
        $rows = $this->db->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
            $this->db->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * All rows oldest-first, for export.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        $rows = $this->db->get_results("SELECT * FROM {$this->table()} ORDER BY id", ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /** Re-verify the whole chain from GENESIS. False = a row was altered or deleted. */
    public function verifyChain(): bool
    {
        $table = $this->table();
        // phpcs:ignore WordPress.DB.PreparedSQL -- trusted internal table name.
        $rows = $this->db->get_results("SELECT prev_hash, record_json, entry_hash FROM {$table} ORDER BY id", ARRAY_A);
        if (!is_array($rows)) {
            return true; // empty log is trivially valid
        }

        $entries = array_map(static fn ($r) => [
            'prev_hash'      => (string) $r['prev_hash'],
            'canonical_json' => (string) $r['record_json'],
            'entry_hash'     => (string) $r['entry_hash'],
        ], $rows);

        return HashChain::verify($entries);
    }
}
