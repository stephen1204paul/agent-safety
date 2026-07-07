<?php

/**
 * PHPUnit bootstrap for the plugin test suite. Run from woo-agent-safety/ via:
 *   vendor/bin/phpunit -c plugin/phpunit.xml.dist
 *
 * The plugin has no WordPress (or mcp-adapter) install available in CI/local
 * runs, so this defines the minimal WP function shims the code under test
 * actually calls, plus a stand-in for mcp-adapter's public observability
 * interface — ONLY when the real thing isn't already loaded, so this stays a
 * no-op the moment a real WP/mcp-adapter environment (e.g. wp-env) supplies
 * the genuine functions/classes instead.
 */

declare(strict_types=1);

$wpasPluginAutoload = __DIR__ . '/../vendor/autoload.php';
if (!is_readable($wpasPluginAutoload)) {
    fwrite(STDERR, "Missing plugin/vendor/autoload.php — run `composer install` in plugin/ first.\n");
    exit(1);
}
require_once $wpasPluginAutoload;

// Test-only stand-in for mcp-adapter's public observability contract.
if (!interface_exists(\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface::class, false)) {
    require_once __DIR__ . '/stubs/mcp-observability-handler-interface.php';
}

// Hand-rolled fakes used across the suite.
require_once __DIR__ . '/Fakes/InMemoryAuditSink.php';
require_once __DIR__ . '/Fakes/FakeIdentityProvider.php';

// --- Minimal WP function shims -------------------------------------------
// Only defined when absent, so a real WP load order (wp-env) always wins.

if (!function_exists('get_current_user_id')) {
    $GLOBALS['wpas_test_current_user_id'] = 0;

    /** Test control knob: set $GLOBALS['wpas_test_current_user_id'] per-test. */
    function get_current_user_id(): int
    {
        return (int) ($GLOBALS['wpas_test_current_user_id'] ?? 0);
    }
}

if (!function_exists('__')) {
    /**
     * Honors $GLOBALS['wpas_test_translations'][$domain][$text] so tests can
     * simulate a loaded textdomain (a non-English site) — both the emitting
     * code and any consumer mirroring the same __() call must then agree on
     * the SAME translated string, which is exactly the property under test.
     */
    function __(string $text, string $domain = 'default'): string
    {
        return $GLOBALS['wpas_test_translations'][$domain][$text] ?? $text;
    }
}

if (!function_exists('add_filter')) {
    /** @param mixed ...$args */
    function add_filter(...$args): bool
    {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    function apply_filters(string $tag, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('add_action')) {
    /** @param mixed ...$args */
    function add_action(...$args): bool
    {
        return true;
    }
}

if (!function_exists('get_option')) {
    $GLOBALS['wpas_test_options'] = [];

    /**
     * Test control knob: set $GLOBALS['wpas_test_options'][$name] per-test.
     *
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $name, $default = false)
    {
        return $GLOBALS['wpas_test_options'][$name] ?? $default;
    }
}

if (!function_exists('is_wp_error')) {
    /** @param mixed $thing */
    function is_wp_error($thing): bool
    {
        // Safe even though WP_Error may not be defined here: instanceof against
        // an unknown class name simply evaluates to false, no autoload/fatal.
        return $thing instanceof \WP_Error;
    }
}
