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

// Test-only stand-in for WordPress's $wpdb (a real WP load order always wins).
if (!class_exists('wpdb', false)) {
    require_once __DIR__ . '/stubs/wpdb.php';
}

// Test-only stand-in for WordPress's WP_Error (a real WP load order always
// wins). Needed the moment a test drives a gate seam far enough to hit a
// denial/approval-required/execution-failure branch, all of which construct one.
if (!class_exists('WP_Error', false)) {
    require_once __DIR__ . '/stubs/wp-error.php';
}

// Test-only clock override consumed by Support\RateCounter's fixed-window
// bucketing (see the file for why this is a namespaced function, not a class).
require_once __DIR__ . '/stubs/wpas-clock.php';

// Hand-rolled fakes used across the suite.
require_once __DIR__ . '/Fakes/InMemoryAuditSink.php';
require_once __DIR__ . '/Fakes/FakeIdentityProvider.php';
require_once __DIR__ . '/Fakes/FakeToolAnnotations.php';
require_once __DIR__ . '/Fakes/FakeMcpTool.php';

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

if (!function_exists('update_option')) {
    /**
     * Test control knob: mirrors writes into $GLOBALS['wpas_test_options'] so a
     * subsequent get_option() in the SAME test observes them.
     *
     * @param mixed $value
     */
    function update_option(string $name, $value, mixed $autoload = null): bool
    {
        $GLOBALS['wpas_test_options'][$name] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['wpas_test_options'][$name]);

        return true;
    }
}

if (!function_exists('dbDelta')) {
    $GLOBALS['wpas_test_dbdelta_queries'] = [];

    /**
     * Test control knob: records every statement Schema::install() would have
     * handed to the real dbDelta() in $GLOBALS['wpas_test_dbdelta_queries'],
     * instead of diffing against a real database.
     *
     * @param string|list<string> $queries
     * @return array<string, string>
     */
    function dbDelta($queries = '', bool $execute = true): array
    {
        foreach ((array) $queries as $query) {
            $GLOBALS['wpas_test_dbdelta_queries'][] = $query;
        }

        return [];
    }
}

if (!function_exists('wp_next_scheduled')) {
    $GLOBALS['wpas_test_cron'] = [];

    /**
     * Test control knob: $GLOBALS['wpas_test_cron'][$hook] holds the next-run
     * timestamp, mirroring wp-cron's own option-backed schedule.
     *
     * @param array<int, mixed> $args
     */
    function wp_next_scheduled(string $hook, array $args = []): int|false
    {
        return $GLOBALS['wpas_test_cron'][$hook] ?? false;
    }
}

if (!function_exists('wp_schedule_event')) {
    /** @param array<int, mixed> $args */
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        $GLOBALS['wpas_test_cron'][$hook] = $timestamp;

        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    /** @param array<int, mixed> $args */
    function wp_clear_scheduled_hook(string $hook, array $args = []): int|false
    {
        $existed = isset($GLOBALS['wpas_test_cron'][$hook]);
        unset($GLOBALS['wpas_test_cron'][$hook]);

        return $existed ? 1 : false;
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

if (!function_exists('get_transient')) {
    $GLOBALS['wpas_test_transients'] = [];

    /**
     * Test control knob: $GLOBALS['wpas_test_transients'][$key] holds
     * ['value' => mixed, 'expires' => int|null], mirroring the real transients
     * API's options-table backing closely enough for RateCounter's needs.
     * Expiry is measured against the SAME $GLOBALS['wpas_test_time'] clock
     * Support\RateCounter reads (see stubs/wpas-clock.php), so a test that
     * freezes/advances time sees both agree.
     *
     * @return mixed
     */
    function get_transient(string $key)
    {
        $row = $GLOBALS['wpas_test_transients'][$key] ?? null;
        if ($row === null) {
            return false;
        }

        $now = $GLOBALS['wpas_test_time'] ?? time();
        if ($row['expires'] !== null && $row['expires'] <= $now) {
            unset($GLOBALS['wpas_test_transients'][$key]);

            return false;
        }

        return $row['value'];
    }
}

if (!function_exists('set_transient')) {
    /**
     * Test control knob: mirrors writes into $GLOBALS['wpas_test_transients']
     * so a subsequent get_transient() in the SAME test observes them (and their
     * expiry, per the $wpas_test_time clock).
     *
     * @param mixed $value
     */
    function set_transient(string $key, $value, int $expiration = 0): bool
    {
        $now = $GLOBALS['wpas_test_time'] ?? time();
        $GLOBALS['wpas_test_transients'][$key] = [
            'value' => $value,
            'expires' => $expiration > 0 ? $now + $expiration : null,
        ];

        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        $existed = isset($GLOBALS['wpas_test_transients'][$key]);
        unset($GLOBALS['wpas_test_transients'][$key]);

        return $existed;
    }
}
