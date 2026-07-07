<?php

/**
 * Runs on "Delete" from the Plugins screen (WordPress requires this to be a
 * standalone file — no bootstrap, no autoloader guaranteed).
 *
 * Default behaviour: KEEP all data. The audit log is a compliance record
 * (SPEC §5/§6, PCI Req-10 shape); silently destroying it on uninstall would
 * defeat the point of having it. Table data and options are only dropped when
 * the site operator explicitly opts in by defining AGSAFE_REMOVE_DATA as true
 * (e.g. in wp-config.php) before deleting the plugin.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!defined('AGSAFE_REMOVE_DATA') || AGSAFE_REMOVE_DATA !== true) {
    return;
}

global $wpdb;
if (!isset($wpdb)) {
    return;
}

// Table names duplicated as literals (mirroring Schema::auditLogTable() /
// Schema::approvalsTable()) rather than requiring the composer autoloader:
// uninstall.php runs standalone and must not depend on `composer install`
// having ever succeeded in plugin/.
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'agsafe_audit_log');
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'agsafe_approvals');

delete_option('agsafe_schema_version');
delete_option('agsafe_pack_bindings');
