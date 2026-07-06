<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Plugin\Audit;

use Specflux\WooAgentSafety\Audit\AuditRecord;
use Specflux\WooAgentSafety\Audit\AuditSink;
use Specflux\WooAgentSafety\Audit\HashChain;
use wpdb;

/**
 * Append-only audit store backed by a custom WordPress table. Each row links into
 * the {@see HashChain}: prev_hash = the prior row's entry_hash (GENESIS for the
 * first), entry_hash = sha256(prev_hash + canonical_json). Tampering with or
 * deleting any row is then detectable by re-verifying the chain.
 *
 * The table is created lazily on first write (CREATE TABLE IF NOT EXISTS, guarded
 * once per request) so the sink works regardless of plugin-activation timing in a
 * mapped/dev environment.
 *
 * Concurrency: reading the previous hash and inserting must not interleave, or two
 * writers could read the same prev hash and fork the chain. The append is therefore
 * serialized with a MySQL advisory lock (GET_LOCK), which also covers the
 * empty-table head case that row locking alone would miss.
 */
final class WpdbAuditSink implements AuditSink
{
    /** Advisory-lock name serializing all appends to this log. */
    private const LOCK = 'was_audit_append';

    private bool $ensured = false;

    public function __construct(private readonly wpdb $db)
    {
    }

    public function table(): string
    {
        return $this->db->prefix . 'was_audit_log';
    }

    public function append(AuditRecord $record): void
    {
        $this->ensureTable();

        $table = $this->table();
        $json = $record->canonicalJson();
        $data = $record->toArray();

        $this->lock();
        try {
            $prev = $this->lastHash();
            $entryHash = HashChain::entryHash($prev, $json);

            $this->db->insert(
                $table,
                [
                    'event_id' => $record->id,
                    'ts' => $record->ts,
                    'correlation_id' => $record->correlationId,
                    'pack' => $record->pack,
                    'ability' => $record->ability,
                    'tier' => $record->tier,
                    'decision' => $record->decision->value,
                    'result' => $record->result,
                    'wp_user' => $data['actor']['wp_user'],
                    'ip' => $record->ip,
                    'record_json' => $json,
                    'prev_hash' => $prev,
                    'entry_hash' => $entryHash,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s'],
            );
        } finally {
            $this->unlock();
        }
    }

    /** Serialize appends so concurrent writers can't read the same prev hash. */
    private function lock(): void
    {
        $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s, %d)', self::LOCK, 5));
    }

    private function unlock(): void
    {
        $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', self::LOCK));
    }

    /** The most recent entry's hash, or GENESIS if the log is empty. */
    private function lastHash(): string
    {
        $table = $this->table();
        // phpcs:ignore WordPress.DB.PreparedSQL -- table name is a trusted internal constant.
        $hash = $this->db->get_var("SELECT entry_hash FROM {$table} ORDER BY id DESC LIMIT 1");

        return is_string($hash) && $hash !== '' ? $hash : HashChain::GENESIS;
    }

    private function ensureTable(): void
    {
        if ($this->ensured) {
            return;
        }

        $table = $this->table();
        $charset = $this->db->get_charset_collate();

        // dbDelta is finicky; a direct guarded CREATE is simpler and idempotent.
        $this->db->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id VARCHAR(64) NOT NULL,
                ts VARCHAR(40) NOT NULL,
                correlation_id VARCHAR(64) NOT NULL,
                pack VARCHAR(100) NOT NULL,
                ability VARCHAR(191) NOT NULL,
                tier TINYINT NULL,
                decision VARCHAR(20) NOT NULL,
                result VARCHAR(20) NULL,
                wp_user BIGINT NULL,
                ip VARCHAR(45) NULL,
                record_json LONGTEXT NOT NULL,
                prev_hash CHAR(64) NOT NULL,
                entry_hash CHAR(64) NOT NULL,
                PRIMARY KEY (id),
                KEY correlation_id (correlation_id),
                KEY ability (ability)
            ) {$charset}"
        );

        $this->ensured = true;
    }
}
