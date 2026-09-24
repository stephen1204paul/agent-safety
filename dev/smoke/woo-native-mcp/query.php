<?php
/**
 * `wp eval-file` helper for run.js: runs the SQL in the AGSAFE_SQL env var
 * and prints the result rows as JSON.
 *
 * Deliberately NOT `wp db query`: WP-CLI's db query decides whether a query
 * is a SELECT by scanning for DML keywords ANYWHERE in the query text, not
 * just at the start — a WHERE clause literal like 'woocommerce/products-delete'
 * or 'woocommerce/products-update' contains "delete"/"update" and gets
 * misreported as "Success: Query succeeded. Rows affected: -1" instead of
 * returning rows (a known wp-env/WP-CLI gotcha). Going straight to $wpdb
 * sidesteps that heuristic entirely.
 */

defined('ABSPATH') || exit;

global $wpdb;
$sql = getenv('AGSAFE_SQL');
if (!is_string($sql) || $sql === '') {
    fwrite(STDERR, "AGSAFE_SQL env var is empty.\n");
    exit(1);
}

$rows = $wpdb->get_results($sql, ARRAY_A);
if ($rows === null) {
    fwrite(STDERR, 'DB error: ' . $wpdb->last_error . "\n");
    exit(1);
}

echo json_encode($rows) . "\n";
