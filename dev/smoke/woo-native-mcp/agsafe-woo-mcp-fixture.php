<?php
/**
 * Plugin Name: Agent Safety Woo-native-MCP Smoke Fixture
 * Description: LOCAL DEV ONLY. Supports the stage-3 wp-env leg that proves
 *              Agent Safety governs WooCommerce 11.1's OWN native MCP server
 *              (Woo's deprecated `/wp-json/woocommerce/mcp` transport, its
 *              bundled mcp-adapter v0.3.0). Two things live here:
 *
 *              1. wp-env is plain HTTP; Woo's transport refuses non-TLS
 *                 requests unless this filter opts in (dev-only, never ship).
 *              2. A single test-only `woocommerce/`-namespaced ability that
 *                 Agent Safety does NOT catalogue (WooVerbCatalog::MAP), so
 *                 case 3 of the e2e proves the fail-closed unknown_verb path
 *                 on a real ability Woo's own MCP server actually exposes —
 *                 not a namespace AS invented for itself.
 */

defined('ABSPATH') || exit;

// ---- 1. Let wp-env's plain-HTTP site through Woo's MCP transport ----------

add_filter('woocommerce_mcp_allow_insecure_transport', '__return_true');

// ---- 2. An uncatalogued woocommerce/ ability, exposed on Woo's deprecated --
//         MCP endpoint the same way Woo exposes its own REST-derived ones.

add_action('wp_abilities_api_init', static function (): void {
    wp_register_ability('woocommerce/agsafe-smoke-widget-list', [
        'label'         => 'Smoke: list widgets (uncatalogued)',
        'description'   => 'Test-only woocommerce/ ability Agent Safety does not catalogue: proves the fail-closed unknown_verb path on Woo\'s own MCP server.',
        'category'      => 'woocommerce',
        'input_schema'  => ['type' => 'object', 'additionalProperties' => true],
        'output_schema' => ['type' => 'object'],
        'execute_callback' => static function (): array {
            $n = (int) get_option('agsafe_smoke_exec_woo_widget_list', 0);
            update_option('agsafe_smoke_exec_woo_widget_list', $n + 1, false);
            return ['ok' => true, 'widgets' => []];
        },
        // Same bar Woo's own read abilities set: any authenticated MCP caller.
        'permission_callback' => static fn (): bool => current_user_can('read'),
        'meta' => [
            'show_in_rest' => true,
            // Woo's MCPAdapterProvider only puts abilities carrying this exact
            // meta key/value on the deprecated woocommerce/mcp tool list
            // (RestAbilityFactory::EXPOSE_IN_DEPRECATED_MCP_META_KEY).
            'expose_in_deprecated_woocommerce_mcp' => true,
        ],
    ]);
}, 20);

// WordPress core registers the 'woocommerce' ability category itself once
// WooCommerce is active; register a fallback so this file has no activation-
// order dependency on that if it ever changes.
add_action('wp_abilities_api_categories_init', static function (): void {
    if (function_exists('wp_get_ability_category') && wp_get_ability_category('woocommerce')) {
        return;
    }
    wp_register_ability_category('woocommerce', [
        'label'       => 'WooCommerce',
        'description' => 'WooCommerce abilities.',
    ]);
}, 5);
