<?php
/**
 * `wp eval-file` provisioning script for the stage-3 Woo-native-MCP e2e.
 *
 * Run inside the wp-env cli container AFTER WooCommerce and Agent Safety are
 * both active. Idempotent: re-running it reuses the existing Shop Manager
 * user, mints a fresh API key each time (cheap, and avoids ever needing to
 * read back a secret WooCommerce never stores in plaintext), and re-seeds the
 * product only if the marker product is missing.
 *
 * Deliberately a `wp eval-file` (not `wp eval "..."`): PHP `$vars` inside a
 * double-quoted shell string are eaten by the shell, and this script builds
 * WooCommerce's own API-key rows by hand (WC_Ajax::update_api_key's exact
 * shape — see class-wc-ajax.php in WooCommerce 11.1.0), which needs real
 * PHP, not a one-liner.
 *
 * Prints ONE line of JSON to stdout; nothing else in this file may echo.
 */

defined('ABSPATH') || exit;

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

// ---- 1. Enable Woo's experimental, off-by-default MCP integration --------
update_option('woocommerce_feature_mcp_integration_enabled', 'yes');

// ---- 2. Shop Manager user (not an administrator: a pass here can't come --
//         from admin capabilities — spec 3.2.5).
$username = 'agsafe_shop_manager';
$user = get_user_by('login', $username);
if (!$user) {
    $user_id = wp_insert_user([
        'user_login' => $username,
        'user_pass'  => wp_generate_password(24),
        'user_email' => $username . '@example.test',
        'role'       => 'shop_manager',
    ]);
    if (is_wp_error($user_id)) {
        fwrite(STDERR, 'Could not create shop manager: ' . $user_id->get_error_message() . "\n");
        exit(1);
    }
} else {
    $user_id = $user->ID;
}

// ---- 3. WooCommerce REST API key, read_write, owned by that user ---------
// Mirrors WC_Ajax::update_api_key()'s insert exactly (consumer_key hashed at
// rest, consumer_secret stored plain — that's what WooCommerceRestTransport
// and AS's WcApiKeyIdentity both compare against with hash_equals()).
global $wpdb;
$consumer_key    = 'ck_' . wc_rand_hash();
$consumer_secret = 'cs_' . wc_rand_hash();
$wpdb->insert(
    $wpdb->prefix . 'woocommerce_api_keys',
    [
        'user_id'         => $user_id,
        'description'     => 'agsafe stage-3 e2e',
        'permissions'     => 'read_write',
        'consumer_key'    => wc_api_hash($consumer_key),
        'consumer_secret' => $consumer_secret,
        'truncated_key'   => substr($consumer_key, -7),
    ],
    ['%d', '%s', '%s', '%s', '%s', '%s']
);
$key_id = (int) $wpdb->insert_id;

// ---- 4. Bind wc:<key_id> to woo-default-agent, not the fallback pack -----
$bindings = get_option('agsafe_pack_bindings', []);
if (!is_array($bindings)) {
    $bindings = [];
}
$bindings['wc:' . $key_id] = 'woo-default-agent';
update_option('agsafe_pack_bindings', $bindings, false);

// ---- 5. Seed one product the smoke can list/delete -----------------------
$product_id = (int) get_option('agsafe_smoke_woo_product_id', 0);
if ($product_id <= 0 || get_post_status($product_id) === false) {
    $product = new WC_Product_Simple();
    $product->set_name('AgSafe Stage 3 Widget');
    $product->set_regular_price('9.99');
    $product->set_status('publish');
    $product_id = $product->save();
    update_option('agsafe_smoke_woo_product_id', $product_id, false);
}

echo json_encode([
    'user_id'         => $user_id,
    'key_id'          => $key_id,
    'consumer_key'    => $consumer_key,
    'consumer_secret' => $consumer_secret,
    'product_id'      => $product_id,
]) . "\n";
