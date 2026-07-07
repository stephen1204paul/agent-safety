<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use wpdb;

/**
 * Single source of truth for the plugin's two custom tables
 * ({@see \Specflux\AgentSafety\Plugin\Audit\WpdbAuditSink} and
 * {@see \Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore}) and their
 * versioned installation.
 *
 * {@see install()} runs on activation (and on {@see maybeUpgrade()} whenever the
 * stored version falls behind) via `dbDelta()`, which diffs the declared shape
 * against what actually exists and issues only the ALTERs needed — safe to
 * rerun on every upgrade. The lazy `CREATE TABLE IF NOT EXISTS` fallbacks in
 * the two host classes exist only for a site that writes before it is ever
 * (re-)activated (e.g. after a manual file update); they build their
 * statements from the SAME column definitions below so the two paths can
 * never drift apart.
 *
 * dbDelta parses `CREATE TABLE {name} (` with a regex that breaks if the
 * statement includes `IF NOT EXISTS` (it would capture "IF" as the table
 * name), so {@see install()} must NOT add that clause — only the lazy
 * fallbacks may.
 */
final class Schema
{
    /**
     * Bump whenever the column defintions below change; {@see maybeUpgrade()}
     * reinstalls (dbDelta-diffs) once the stored option falls behind this.
     */
    public const VERSION = '1';

    public const VERSION_OPTION = 'agsafe_schema_version';

    public static function auditLogTable(wpdb $db): string
    {
        return $db->prefix . 'agsafe_audit_log';
    }

    public static function approvalsTable(wpdb $db): string
    {
        return $db->prefix . 'agsafe_approvals';
    }

    /** Column/key body (no surrounding `CREATE TABLE ... ( )`) for the audit log table. */
    public static function auditLogColumns(): string
    {
        return "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
                PRIMARY KEY  (id),
                KEY correlation_id (correlation_id),
                KEY ability (ability)";
    }

    /** Column/key body (no surrounding `CREATE TABLE ... ( )`) for the approvals table. */
    public static function approvalsColumns(): string
    {
        return "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
                PRIMARY KEY  (id),
                UNIQUE KEY approval_id (approval_id),
                KEY status (status),
                KEY verb_args (verb, args_hash),
                KEY ref (key_id, verb, args_hash),
                KEY token_hash (token_hash)";
    }

    /**
     * Create/upgrade both tables via `dbDelta()` and record the version that
     * was just installed. Safe to call repeatedly (dbDelta only issues the
     * ALTERs a diff actually needs).
     */
    public static function install(wpdb $db): void
    {
        self::ensureDbDelta();

        $charset = $db->get_charset_collate();
        dbDelta([
            'CREATE TABLE ' . self::auditLogTable($db) . " (\n" . self::auditLogColumns() . "\n) {$charset};",
            'CREATE TABLE ' . self::approvalsTable($db) . " (\n" . self::approvalsColumns() . "\n) {$charset};",
        ]);

        update_option(self::VERSION_OPTION, self::VERSION, false);
    }

    /** Cheap version check; reinstalls only when the stored option is behind {@see VERSION}. */
    public static function maybeUpgrade(wpdb $db): void
    {
        if (get_option(self::VERSION_OPTION, '') === self::VERSION) {
            return;
        }

        self::install($db);
    }

    private static function ensureDbDelta(): void
    {
        if (function_exists('dbDelta')) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    }
}
