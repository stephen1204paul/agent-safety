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

// The global agent_safety() service locator — function_exists-guarded, and
// require-once idempotent with the real plugin main file's own load of it.
require_once __DIR__ . '/../src/api.php';

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
require_once __DIR__ . '/Fakes/FakeApprovalStore.php';
require_once __DIR__ . '/Fakes/FakeGrantStore.php';
require_once __DIR__ . '/Fakes/FakeIdentityProvider.php';
require_once __DIR__ . '/Fakes/FakeToolAnnotations.php';
require_once __DIR__ . '/Fakes/FakeMcpTool.php';
require_once __DIR__ . '/Fixtures/VerdictErrorFixture.php';

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

if (!function_exists('current_user_can')) {
    $GLOBALS['wpas_test_user_caps'] = [];

    /**
     * Test control knob: set $GLOBALS['wpas_test_user_caps'][$capability]
     * per-test; absent capabilities default to false (fail closed, like core).
     */
    function current_user_can(string $capability): bool
    {
        return (bool) ($GLOBALS['wpas_test_user_caps'][$capability] ?? false);
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

$GLOBALS['wpas_test_filters'] = [];

if (!function_exists('add_filter')) {
    /** @param mixed ...$args */
    function add_filter(string $hook, $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wpas_test_filters'][$hook][$priority][] = [$callback, $accepted_args];

        return true;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * WP-style value threading through priority-ordered callbacks. A working
     * registry (was a pass-through no-op): with no registered callbacks the
     * behaviour is identical, so existing tests are unaffected; filter-contract
     * tests (AS-11+) need real dispatch.
     *
     * @param mixed $value
     * @param mixed ...$args
     * @return mixed
     */
    function apply_filters(string $tag, $value, ...$args)
    {
        $callbacks = $GLOBALS['wpas_test_filters'][$tag] ?? [];
        ksort($callbacks);
        foreach ($callbacks as $group) {
            foreach ($group as [$callback, $accepted_args]) {
                $value = $callback($value, ...array_slice($args, 0, max(0, $accepted_args - 1)));
            }
        }

        return $value;
    }
}

if (!function_exists('remove_all_filters')) {
    /** Test control knob: drop every callback for one hook (or all hooks). */
    function remove_all_filters(?string $hook = null): void
    {
        if ($hook === null) {
            $GLOBALS['wpas_test_filters'] = [];

            return;
        }
        unset($GLOBALS['wpas_test_filters'][$hook]);
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

if (!function_exists('do_action')) {
    $GLOBALS['wpas_test_actions'] = [];

    /**
     * Test control knob: fired actions are recorded into
     * $GLOBALS['wpas_test_actions'] as [hook, ...args] so a test can assert
     * exactly which hooks fired (and how often). Callbacks are NOT dispatched
     * — the add_action shim above is a no-op, matching how these tests drive
     * subscribers directly.
     *
     * @param mixed ...$args
     */
    function do_action(string $hook, ...$args): void
    {
        $GLOBALS['wpas_test_actions'][] = array_merge([$hook], $args);
    }
}

if (!function_exists('wp_mail')) {
    $GLOBALS['wpas_test_mail'] = [];

    /**
     * Test control knob: sent mail is recorded into $GLOBALS['wpas_test_mail']
     * as ['to' =>, 'subject' =>, 'message' =>].
     *
     * @param string|list<string> $to
     */
    function wp_mail($to, string $subject, string $message): bool
    {
        $GLOBALS['wpas_test_mail'][] = ['to' => $to, 'subject' => $subject, 'message' => $message];

        return true;
    }
}

if (!function_exists('wp_remote_post')) {
    $GLOBALS['wpas_test_http'] = [];

    /**
     * Test control knob: outgoing POSTs are recorded into
     * $GLOBALS['wpas_test_http'] as ['url' =>, 'args' =>].
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    function wp_remote_post(string $url, array $args = []): array
    {
        $GLOBALS['wpas_test_http'][] = ['url' => $url, 'args' => $args];

        return [];
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('wpas_test_kses_protocol_ok')) {
    /**
     * The shim's stand-in for core's wp_kses_bad_protocol(): is this URL-bearing
     * attribute value safe to keep?
     *
     * Core normalises before it compares a scheme, and so must this shim — a
     * shim that waves `javascript:` through would let the summary-cell tests
     * pass while the real screen is still injectable, which is exactly the
     * failure this harness exists to catch. Normalisation covers the tricks a
     * browser itself tolerates: entity-encoded colons (`&#58;`, `&#x3a;`,
     * `&colon;`), leading/embedded whitespace and control characters
     * (`java\tscript:`), and scheme case (`JaVaScRiPt:`).
     *
     * Relative, root-relative, query-only and anchor-only URLs have no scheme
     * and are kept. Anything with a scheme outside the allow-list is REJECTED —
     * the shim drops the whole attribute where core strips just the scheme;
     * stricter than core, and never the other way round.
     */
    function wpas_test_kses_protocol_ok(string $value): bool
    {
        $safe_protocols = ['http', 'https', 'mailto'];

        // Decode the colon tricks first (with or without the closing semicolon,
        // which browsers tolerate), then html_entity_decode for the rest.
        $normalised = preg_replace(['/&#0*58;?/i', '/&#x0*3a;?/i'], ':', $value);
        if (!is_string($normalised)) {
            return false; // Fail closed: could not normalise, so cannot vouch for it.
        }
        $normalised = str_ireplace('&colon;', ':', $normalised);
        $normalised = html_entity_decode($normalised, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Whitespace and control characters are ignored by browsers inside a scheme.
        $normalised = preg_replace('/[\x00-\x20\x7F]+/', '', $normalised);
        if (!is_string($normalised)) {
            return false; // Fail closed.
        }

        if ($normalised === '') {
            return true; // An empty href is inert.
        }
        if (!preg_match('#^([a-zA-Z][a-zA-Z0-9+.\-]*):#', $normalised, $scheme)) {
            return true; // No scheme at all: relative / root-relative / `?` / `#`.
        }

        return in_array(strtolower($scheme[1]), $safe_protocols, true);
    }
}

if (!function_exists('wp_kses')) {
    /**
     * SHIM for the summary-cell contract (AS-11): strips every tag not in the
     * allow-list, every attribute not allowed for its tag, and every URL-bearing
     * attribute whose protocol is not in the safe allow-list (see
     * {@see wpas_test_kses_protocol_ok()}). Minimal stand-in - the REAL
     * sanitisation check is a wp-env smoke against core's wp_kses; this shim
     * only locks the call shape and the protocol contract so the unit suite can
     * exercise the render path.
     *
     * @param string               $string       Content to sanitise.
     * @param array<string, mixed> $allowed_html Allowed tags => allowed attributes.
     */
    function wp_kses(string $string, array $allowed_html = []): string
    {
        $allowed_tags = array_keys($allowed_html);
        $url_attributes = ['href', 'src', 'action', 'formaction', 'xlink:href', 'background', 'cite', 'longdesc'];

        $result = preg_replace_callback(
            '/<\s*\/?\s*([a-zA-Z0-9]+)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
            static function (array $m) use ($allowed_tags, $allowed_html, $url_attributes): string {
                $closing = str_starts_with(ltrim($m[0]), '</');
                $tag = strtolower($m[1]);
                if (!in_array($tag, $allowed_tags, true)) {
                    return ''; // Not allowed: the whole element goes.
                }
                if ($closing) {
                    return '</' . $tag . '>';
                }
                $attrs = $allowed_html[$tag] ?? [];
                $kept = '';
                foreach ($attrs as $attr => $enabled) {
                    if (!$enabled) {
                        continue;
                    }
                    $attr_name = (string) $attr;
                    if (!preg_match('/\b' . preg_quote($attr_name, '/') . '\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', $m[2], $am)) {
                        continue;
                    }
                    $raw = $am[1];
                    $quote = '"';
                    if (strlen($raw) >= 2 && ($raw[0] === '"' || $raw[0] === "'") && $raw[-1] === $raw[0]) {
                        $quote = $raw[0];
                        $raw = substr($raw, 1, -1);
                    }
                    if (
                        in_array(strtolower($attr_name), $url_attributes, true)
                        && !wpas_test_kses_protocol_ok($raw)
                    ) {
                        continue; // Bad protocol: drop the attribute entirely.
                    }
                    $kept .= ' ' . strtolower($attr_name) . '=' . $quote . $raw . $quote;
                }

                return '<' . $tag . $kept . '>';
            },
            $string
        );

        return is_string($result) ? $result : $string;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr(__($text, $domain));
    }
}

if (!function_exists('wp_nonce_field')) {
    /** @return string|null Echoes when $echo, mirroring core's signature. */
    function wp_nonce_field(
        string $action = '-1',
        string $name = '_wpnonce',
        bool $referer = true,
        bool $echo = true
    ): ?string {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
        if ($echo) {
            echo $field; // phpcs:ignore WordPress.Security.EscapeOutput -- test shim.

            return null;
        }

        return $field;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * @param mixed $data
     * @return string|false
     */
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}
