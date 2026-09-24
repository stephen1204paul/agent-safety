<?php

declare(strict_types=1);

/**
 * A wpdb stand-in that actually behaves like the audit-log table for the
 * queries {@see \Specflux\AgentSafety\Plugin\Privacy\PrivacyAuditReader} and
 * {@see \Specflux\AgentSafety\Plugin\Audit\AuditReader} issue against it,
 * instead of the base test stub's canned-return behaviour (tests/stubs/wpdb.php).
 * Both classes issue only a handful of recognisable query shapes, so this
 * fake recognises them from the rendered SQL string (wpdb::prepare() already
 * bakes %d in bare and %s quoted) and answers from an in-memory row set —
 * enough to make "does the WHERE actually filter" and "does paging actually
 * page" real, failable assertions instead of assertions on canned output.
 */
final class InMemoryAuditRowsWpdb extends wpdb
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @param list<array<string, mixed>> $rows Row shape: id, wp_user, prev_hash, record_json, entry_hash, ... */
    public function seed(array $rows): void
    {
        $this->rows = $rows;
    }

    public function get_var(?string $query = null): mixed
    {
        if ($query !== null && preg_match('/SELECT COUNT\(\*\).*WHERE wp_user = (\d+)/s', $query, $m) === 1) {
            $userId = (int) $m[1];

            return count(array_filter($this->rows, static fn (array $r): bool => (int) $r['wp_user'] === $userId));
        }

        return parent::get_var($query);
    }

    /** @return list<array<string, mixed>> */
    public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
    {
        if ($query !== null && preg_match('/WHERE wp_user = (\d+).*LIMIT (\d+) OFFSET (\d+)/s', $query, $m) === 1) {
            $userId = (int) $m[1];
            $limit = (int) $m[2];
            $offset = (int) $m[3];

            $matched = array_values(array_filter(
                $this->rows,
                static fn (array $r): bool => (int) $r['wp_user'] === $userId
            ));
            usort($matched, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

            return array_slice($matched, $offset, $limit);
        }

        if ($query !== null && str_contains($query, 'prev_hash, record_json, entry_hash')) {
            $ordered = $this->rows;
            usort($ordered, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

            return $ordered;
        }

        return parent::get_results($query, $output);
    }
}
