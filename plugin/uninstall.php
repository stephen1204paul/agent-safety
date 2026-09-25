<?php

/**
 * Runs on "Delete" from the Plugins screen (WordPress requires this to be a
 * standalone file — no bootstrap, no autoloader guaranteed).
 *
 * Default behaviour: KEEP all data. The audit log is a compliance record
 * (PCI Req-10 shape); silently destroying it on uninstall would
 * defeat the point of having it. Table data and options are only dropped when
 * the site operator explicitly opts in by defining AGSAFE_REMOVE_DATA as true
 * (e.g. in wp-config.php) before deleting the plugin.
 *
 * The explicit list of everything opting in removes lives in
 * {@see \Specflux\AgentSafety\Plugin\Support\UninstallManifest}, one class
 * with no WordPress dependency, so it is safe to load below even if this
 * plugin's own bootstrap (agent-safety.php) never ran on this request —
 * exactly the standalone constraint this file operates under.
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

// Same bundled autoloader agent-safety.php uses: `composer install` in this
// dir copies the core package into vendor/ and wires PSR-4 for both the core
// and the plugin's own classes, including UninstallManifest. A site that
// deletes the plugin without ever having run `composer install` here (e.g. a
// hand-copied checkout) has no autoloader to load -- in that case there is
// nothing safe to remove beyond the tables this file names directly below,
// so option/cron/transient cleanup is skipped rather than guessed at.
$agsafeUninstallAutoload = __DIR__ . '/vendor/autoload.php';
$agsafeManifestLoaded = false;
if (is_readable($agsafeUninstallAutoload)) {
    require_once $agsafeUninstallAutoload;
    $agsafeManifestLoaded = class_exists(\Specflux\AgentSafety\Plugin\Support\UninstallManifest::class);
}

// Tables: three current, plus the pre-v4 grants table name a crashed v4
// upgrade can leave behind (Schema::renameLegacyGrantsTable() never deletes
// it, only renames-or-leaves it -- see Schema.php). %i is the WP 6.2+
// identifier placeholder; each table name here is this plugin's own trusted,
// hard-coded constant, never request input.
$agsafeTableBasenames = $agsafeManifestLoaded
    ? \Specflux\AgentSafety\Plugin\Support\UninstallManifest::TABLE_BASENAMES
    : ['agsafe_audit_log', 'agsafe_approvals', 'agsafe_grants', 'agent_safety_grants'];

foreach ($agsafeTableBasenames as $agsafeTableBasename) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- opt-in uninstall dropping a table this plugin owns exclusively; %i is the WP 6.2+ identifier placeholder, the name is a hard-coded constant, never request input.
    $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $agsafeTableBasename));
}

if (!$agsafeManifestLoaded) {
    return;
}

foreach (\Specflux\AgentSafety\Plugin\Support\UninstallManifest::OPTIONS as $agsafeOption) {
    delete_option($agsafeOption);
}

foreach (\Specflux\AgentSafety\Plugin\Support\UninstallManifest::CRON_HOOKS as $agsafeCronHook) {
    wp_clear_scheduled_hook($agsafeCronHook);
}

// Transients are bucketed per pack/identity/window/subject/approver, so no
// fixed list of full names exists -- delete by a LIKE match against the
// prefix, covering both a transient's value row and its timeout row (this
// plugin never sets a site transient).
foreach (\Specflux\AgentSafety\Plugin\Support\UninstallManifest::TRANSIENT_PREFIXES as $agsafeTransientPrefix) {
    $agsafeValueLike = $wpdb->esc_like('_transient_' . $agsafeTransientPrefix) . '%';
    $agsafeTimeoutLike = $wpdb->esc_like('_transient_timeout_' . $agsafeTransientPrefix) . '%';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opt-in uninstall sweep of this plugin's own transient rows by name prefix; no cache to invalidate on a table being dropped from under it.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        $agsafeValueLike,
        $agsafeTimeoutLike,
    ));
}
