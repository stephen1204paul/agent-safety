<?php
/**
 * `wp eval-file` provisioning script for the release-0.4 e2e proof harness.
 *
 * Run inside the wp-env cli container AFTER WooCommerce and Agent Safety are
 * both active. Idempotent for the user/key/pack-binding/app-password pieces
 * (safe to re-run); ALWAYS mints a brand new product each call, because P2's
 * seed and P3/P4/P5's scenarios each need their own product so they don't
 * collide across script phases.
 *
 * Deliberately a `wp eval-file` (not `wp eval "..."`): PHP `$vars` inside a
 * double-quoted shell string are eaten by the shell, and this script builds
 * WooCommerce's own API-key rows by hand (WC_Ajax::update_api_key's exact
 * shape — see class-wc-ajax.php in WooCommerce 11.1.0), which needs real
 * PHP, not a one-liner.
 *
 * Env in:
 *   AGSAFE_PRODUCT_NAME       optional; else a timestamp-based unique name.
 *   AGSAFE_MINT_APP_PASSWORD  '1' to always mint a fresh admin application
 *                             password this call (default: only mint once,
 *                             tracked by the smoke_release04_app_password_uuid
 *                             option).
 *   AGSAFE_APP_PASSWORD_PACK  pack name to bind app:<uuid> to (default
 *                             'woo-default-agent').
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
//         from admin capabilities).
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
// rest, consumer_secret stored plain).
global $wpdb;
$consumer_key    = 'ck_' . wc_rand_hash();
$consumer_secret = 'cs_' . wc_rand_hash();
$wpdb->insert(
    $wpdb->prefix . 'woocommerce_api_keys',
    [
        'user_id'         => $user_id,
        'description'     => 'agsafe release-0.4 e2e',
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

// ---- 5. Always seed a NEW product: callers need a fresh one per scenario -
$product_name = getenv('AGSAFE_PRODUCT_NAME');
if (!is_string($product_name) || $product_name === '') {
    $product_name = 'AgSafe Release Widget ' . time() . '-' . wp_rand(1000, 9999);
}
$product = new WC_Product_Simple();
$product->set_name($product_name);
$product->set_regular_price('9.99');
$product->set_status('publish');
$product_id = $product->save();

// ---- 6. Administrator application password bound to a pack (P5) ---------
$admin = get_user_by('login', 'admin');
if (!$admin) {
    fwrite(STDERR, "Could not find the 'admin' user.\n");
    exit(1);
}
$admin_user_id = $admin->ID;

$marker_option = 'smoke_release04_app_password_uuid';
$mint_fresh    = getenv('AGSAFE_MINT_APP_PASSWORD') === '1';
$existing_uuid = get_option($marker_option, '');

$app_password_uuid   = is_string($existing_uuid) ? $existing_uuid : '';
$app_password_secret = '';

if ($mint_fresh || $app_password_uuid === '') {
    if (!class_exists('WP_Application_Passwords')) {
        fwrite(STDERR, "WP_Application_Passwords is not available.\n");
        exit(1);
    }

    $created = WP_Application_Passwords::create_new_application_password(
        $admin_user_id,
        ['name' => 'agsafe-release-e2e-' . time() . '-' . wp_rand(1000, 9999)]
    );
    if (is_wp_error($created)) {
        fwrite(STDERR, 'Could not create application password: ' . $created->get_error_message() . "\n");
        exit(1);
    }

    [$app_password_secret, $item] = $created;
    $app_password_uuid = (string) ($item['uuid'] ?? '');
    update_option($marker_option, $app_password_uuid, false);
}

// ---- 7. Bind app:<uuid> to the requested pack (default woo-default-agent) -
$app_pack = getenv('AGSAFE_APP_PASSWORD_PACK');
if (!is_string($app_pack) || $app_pack === '') {
    $app_pack = 'woo-default-agent';
}
if ($app_password_uuid !== '') {
    $bindings = get_option('agsafe_pack_bindings', []);
    if (!is_array($bindings)) {
        $bindings = [];
    }
    $bindings['app:' . $app_password_uuid] = $app_pack;
    update_option('agsafe_pack_bindings', $bindings, false);
}

echo json_encode([
    'user_id'             => $user_id,
    'key_id'              => $key_id,
    'consumer_key'        => $consumer_key,
    'consumer_secret'     => $consumer_secret,
    'product_id'          => $product_id,
    'product_name'        => $product_name,
    'app_password_uuid'   => $app_password_uuid,
    'app_password_secret' => $app_password_secret,
    'admin_user_id'       => $admin_user_id,
]) . "\n";
