<?php

/**
 * Subprocess harness for DuplicateGuardTest: loads the real senrogate.php with
 * stub WordPress functions in a fresh PHP process (the only way to exercise
 * compile-time function hoisting) and prints what it registered as JSON.
 *
 * argv[1]: "old" (predeclare Agent Safety's activate function) | "clean" |
 *          "listed" (old plugin only listed in active_plugins, not yet loaded)
 */

declare(strict_types=1);

namespace {
    $mode = $argv[1] ?? 'clean';
    define('ABSPATH', __DIR__ . '/');
    $GLOBALS['agsafe_log'] = ['actions' => [], 'activation' => 0, 'can' => true];

    function add_action($hook, $cb = null, $p = 10, $n = 1)
    {
        $GLOBALS['agsafe_log']['actions'][$hook][] = $cb;
    }
    function add_filter($hook, $cb = null, $p = 10, $n = 1)
    {
    }
    function register_activation_hook($f, $cb)
    {
        $GLOBALS['agsafe_log']['activation_cb'] = $cb;
        $GLOBALS['agsafe_log']['activation']++;
    }
    function register_deactivation_hook($f, $cb)
    {
    }
    function __($t, $d = 'default')
    {
        return $t;
    }
    function esc_html__($t, $d = 'default')
    {
        return $t;
    }
    function esc_html($t)
    {
        return $t;
    }
    function current_user_can($c)
    {
        return 'activate_plugins' === $c && $GLOBALS['agsafe_log']['can'];
    }
    function plugin_basename($f)
    {
        return 'senrogate/senrogate.php';
    }
    function deactivate_plugins($p)
    {
        $GLOBALS['agsafe_log']['deactivated'] = $p;
    }
    function wp_die($m, $t = '', $a = [])
    {
        $GLOBALS['agsafe_log']['died'] = $m;
    }
    if ('listed' === $mode) {
        function get_option($k, $d = false)
        {
            return 'active_plugins' === $k ? ['agent-safety/agent-safety.php'] : $d;
        }
    }
}

namespace Specflux\AgentSafety\Plugin {
    if ('old' === ($GLOBALS['argv'][1] ?? '')) {
        // What Agent Safety 0.4.x declares unconditionally.
        function activate_agent_safety(): void
        {
        }
    }
}

namespace {
    require __DIR__ . '/../../senrogate.php';

    $log = $GLOBALS['agsafe_log'];
    $out = [
        'loaded' => true,
        'plugins_loaded' => count($log['actions']['plugins_loaded'] ?? []),
        'activation_registered' => $log['activation'],
    ];

    $notice = $log['actions']['admin_notices'][0] ?? null;
    if (null !== $notice) {
        ob_start();
        $notice();
        $out['notice'] = ob_get_clean();
        $GLOBALS['agsafe_log']['can'] = false;
        ob_start();
        $notice();
        $out['notice_without_cap'] = ob_get_clean();
    }
    if (isset($log['activation_cb']) && 'old' === $mode) {
        ($log['activation_cb'])();
        $out['died'] = $GLOBALS['agsafe_log']['died'] ?? null;
        $out['deactivated'] = $GLOBALS['agsafe_log']['deactivated'] ?? null;
    }
    echo json_encode($out);
}
