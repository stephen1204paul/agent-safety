<?php

declare(strict_types=1);

/**
 * Test-only stand-in for WordPress's $wpdb, defined ONLY when a real WP
 * environment (e.g. wp-env) hasn't already loaded the genuine class. It is
 * NOT a SQL engine: query()/get_var()/get_row()/get_results() don't execute
 * anything — they record the exact string they were given and return
 * whatever the test pre-loaded onto the matching *Return property. Good
 * enough for callers under test that build a query and act on wpdb's return
 * value, per the house style of hand-rolled fakes over a mocking framework.
 */
if (!class_exists('wpdb', false)) {
    class wpdb
    {
        public string $prefix = 'wp_';

        /** @var list<string> Every SQL string passed to any of the methods below, in call order. */
        public array $queries = [];

        /** Canned return for the next query() call. */
        public int|bool $queryReturn = 0;

        /** Canned return for the next get_var() call. */
        public mixed $varReturn = null;

        /** @var array<string, mixed>|null Canned return for the next get_row() call. */
        public ?array $rowReturn = null;

        /** @var list<array<string, mixed>> Canned return for the next get_results() call. */
        public array $resultsReturn = [];

        /** @var array{table: string, data: array<string, mixed>}|null Captured payload from the last insert(). */
        public ?array $lastInsert = null;

        public function get_charset_collate(): string
        {
            return 'DEFAULT CHARACTER SET utf8mb4';
        }

        /**
         * Approximates real wpdb::prepare()'s placeholder substitution (%s
         * quoted, %d left bare) closely enough to produce readable, assertable
         * SQL — it is not SQL-injection-safe and must never be used outside tests.
         *
         * @param mixed ...$args
         */
        public function prepare(string $query, ...$args): string
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }

            $quoted = (string) preg_replace('/(?<!%)%s/', "'%s'", $query);

            return vsprintf($quoted, $args);
        }

        public function query(string $query): int|bool
        {
            $this->queries[] = $query;

            return $this->queryReturn;
        }

        public function get_var(?string $query = null): mixed
        {
            if ($query !== null) {
                $this->queries[] = $query;
            }

            return $this->varReturn;
        }

        /** @return array<string, mixed>|null */
        public function get_row(?string $query = null, string $output = 'ARRAY_A'): ?array
        {
            if ($query !== null) {
                $this->queries[] = $query;
            }

            return $this->rowReturn;
        }

        /** @return list<array<string, mixed>> */
        public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
        {
            if ($query !== null) {
                $this->queries[] = $query;
            }

            return $this->resultsReturn;
        }

        /**
         * @param array<string, mixed> $data
         * @param list<string>|null $format
         */
        public function insert(string $table, array $data, ?array $format = null): int
        {
            $this->lastInsert = ['table' => $table, 'data' => $data];

            return 1;
        }
    }
}
