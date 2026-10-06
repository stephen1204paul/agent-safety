<?php

declare(strict_types=1);

/**
 * A wpdb stand-in that behaves like the `agsafe_counters` table for the three
 * statements {@see \Specflux\AgentSafety\Plugin\Support\AtomicCounterStore}
 * issues, instead of the base stub's canned returns (tests/stubs/wpdb.php).
 *
 * Each statement is matched against its WHOLE expected shape, so a change to
 * the store's SQL makes the fake stop recognising it (and the tests fail)
 * rather than silently modelling something else. The INSERT is applied as
 * one indivisible step with real ON DUPLICATE KEY UPDATE semantics — insert
 * when the key is absent, else `value = value + <delta>` and
 * `expires_at = GREATEST(expires_at, <expiry>)` — which is exactly
 * the property the production code relies on for atomicity. Expiry is judged
 * against the timestamp the store bakes into the SQL, i.e. the test clock.
 */
final class CounterTableWpdb extends wpdb
{
    /** @var array<string, array{value: float, expires_at: int}> */
    public array $rows = [];

    /**
     * Runs once, right after the next INSERT is applied and before its
     * follow-up SELECT — the window in which a concurrent request can land.
     *
     * @var (callable(): void)|null
     */
    public $afterNextInsert = null;

    /** Install a fresh instance as the global $wpdb the counters default to. */
    public static function install(): self
    {
        $db = new self();
        $GLOBALS['wpdb'] = $db;

        return $db;
    }

    public function query(string $query): int|bool
    {
        $this->queries[] = $query;

        $insert = '/^INSERT INTO \S+ \(counter_key, value, expires_at\) VALUES \(\'([^\']*)\', (-?[\d.]+), (\d+)\) '
            . 'ON DUPLICATE KEY UPDATE value = value \+ \2, expires_at = GREATEST\(expires_at, \3\)$/';
        if (preg_match($insert, $query, $m) === 1) {
            [, $key, $delta, $expires] = $m;
            if (isset($this->rows[$key])) {
                $this->rows[$key]['value'] += (float) $delta;
                $this->rows[$key]['expires_at'] = max($this->rows[$key]['expires_at'], (int) $expires);
            } else {
                $this->rows[$key] = ['value' => (float) $delta, 'expires_at' => (int) $expires];
            }

            if ($this->afterNextInsert !== null) {
                $hook = $this->afterNextInsert;
                $this->afterNextInsert = null;
                $hook();
            }

            return 1;
        }

        if (preg_match('/^DELETE FROM \S+ WHERE expires_at < (\d+) LIMIT (\d+)$/', $query, $m) === 1) {
            $deleted = 0;
            foreach ($this->rows as $key => $row) {
                if ($deleted < (int) $m[2] && $row['expires_at'] < (int) $m[1]) {
                    unset($this->rows[$key]);
                    $deleted++;
                }
            }

            return $deleted;
        }

        return $this->queryReturn;
    }

    public function get_var(?string $query = null): mixed
    {
        $select = '/^SELECT value FROM \S+ WHERE counter_key = \'([^\']*)\'(?: AND expires_at > (\d+))?$/';
        if ($query !== null && preg_match($select, $query, $m) === 1) {
            $this->queries[] = $query;
            $row = $this->rows[$m[1]] ?? null;
            if ($row === null || (isset($m[2]) && $row['expires_at'] <= (int) $m[2])) {
                return null;
            }

            // Real wpdb hands scalars back as strings.
            return (string) $row['value'];
        }

        return parent::get_var($query);
    }
}
