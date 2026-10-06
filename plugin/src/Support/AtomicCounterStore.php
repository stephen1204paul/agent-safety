<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use wpdb;

/**
 * Shared storage for {@see RateCounter}, {@see WindowCounter} and
 * {@see ValueAccumulator}: one row per counter key in the
 * {@see Schema::countersTable()} table.
 *
 * Transients cannot back a counter that gates anything: get_transient +
 * set_transient is a read-modify-write, so concurrent requests lose
 * increments and a cap can be exceeded. {@see add()} instead folds the
 * increment into ONE `INSERT ... ON DUPLICATE KEY UPDATE`, which MySQL
 * applies atomically under the primary-key row lock; every caller reserves
 * first and checks the total it got back, so the check can never race the
 * write.
 *
 * Keys are `{PREFIX}{hash}_{window}_{bucket}` built by the counter classes
 * (at most 64 chars, the column width). A bucket's key never recurs once its
 * window has passed, so an expired row is never added to, only ignored by
 * {@see get()} and eventually swept by {@see purgeExpired()}.
 *
 * Bare time() for the same test clock as {@see RateCounter}.
 */
final class AtomicCounterStore
{
    /** Rows removed per opportunistic sweep. */
    private const PURGE_BATCH = 200;

    /** A sweep runs on roughly one write in this many. */
    private const PURGE_ONE_IN = 50;

    private bool $ensured = false;

    public function __construct(private readonly ?wpdb $db = null)
    {
    }

    /**
     * Atomically add $delta (negative to release) to $key and return the
     * resulting value. The expiry only ever moves forward, so a release never
     * shortens a bucket another request is still counting in.
     */
    public function add(string $key, float $delta, int $ttl): float
    {
        $db = $this->db();
        $table = $this->table();
        $this->ensureTable();

        // The update side repeats the bound values rather than reading
        // VALUES(col): that function is deprecated from MySQL 8.0.20, and its
        // `AS alias` replacement is not understood by MariaDB or older MySQL.
        $expires = time() + $ttl;
        $db->query($db->prepare(
            "INSERT INTO {$table} (counter_key, value, expires_at) VALUES (%s, %f, %d) "
            . 'ON DUPLICATE KEY UPDATE value = value + %f, expires_at = GREATEST(expires_at, %d)',
            $key,
            $delta,
            $expires,
            $delta,
            $expires,
        ));

        $value = $db->get_var($db->prepare("SELECT value FROM {$table} WHERE counter_key = %s", $key));

        if (random_int(1, self::PURGE_ONE_IN) === 1) {
            $this->purgeExpired();
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** The current value of $key; 0 when absent or expired. */
    public function get(string $key): float
    {
        $db = $this->db();
        $table = $this->table();
        $this->ensureTable();

        $value = $db->get_var($db->prepare(
            "SELECT value FROM {$table} WHERE counter_key = %s AND expires_at > %d",
            $key,
            time(),
        ));

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /** Delete up to {@see PURGE_BATCH} expired rows. */
    public function purgeExpired(): void
    {
        $db = $this->db();
        $db->query($db->prepare(
            'DELETE FROM ' . $this->table() . ' WHERE expires_at < %d LIMIT ' . self::PURGE_BATCH,
            time(),
        ));
    }

    private function db(): wpdb
    {
        return $this->db ?? $GLOBALS['wpdb'];
    }

    private function table(): string
    {
        return Schema::countersTable($this->db());
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $charset = $this->db()->get_charset_collate();

        // Safety net only (same posture as the other stores): Schema::install()
        // normally creates the table, from the SAME column definitions.
        $this->db()->query(
            'CREATE TABLE IF NOT EXISTS ' . $this->table() . " (\n" . Schema::countersColumns() . "\n) {$charset}"
        );

        $this->ensured = true;
    }
}
