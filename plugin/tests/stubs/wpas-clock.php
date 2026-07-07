<?php

/**
 * Test-only clock override consumed by {@see \Specflux\AgentSafety\Plugin\Support\RateCounter}'s
 * fixed-window bucketing.
 *
 * PHP resolves an UNQUALIFIED function call by first checking whether a
 * function of that name exists in the CALLING code's own namespace, and only
 * falls back to the global namespace if it does not (see the PHP manual,
 * "Using namespaces: fallback to global function/constant"). RateCounter
 * lives in this exact namespace and calls bare time() — so defining a time()
 * here, loaded ONLY by this test bootstrap, lets tests freeze/advance
 * RateCounter's notion of "now" without touching a single line of production
 * code: outside tests this file is never required, so the bare time() call in
 * RateCounter always falls through to the real global time().
 */

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

if (!function_exists(__NAMESPACE__ . '\\time')) {
    if (!isset($GLOBALS['wpas_test_time'])) {
        $GLOBALS['wpas_test_time'] = \time();
    }

    /** Test control knob: set $GLOBALS['wpas_test_time'] per-test to freeze/advance the clock. */
    function time(): int
    {
        return (int) $GLOBALS['wpas_test_time'];
    }
}
