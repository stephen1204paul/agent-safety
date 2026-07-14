<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Fixed-window day totals backing a Pack's argument-aware caps (roadmap 0.2
 * "spend limits"): the sums {@see \Specflux\AgentSafety\Packs\ArgumentCapPolicy}
 * evaluates `max_total_per_day` against. Storage is WordPress transients,
 * keyed by (pack name, identity token, cap id, UTC-day bucket) — the same
 * design as {@see RateCounter}, summing float magnitudes instead of counting
 * calls.
 *
 * Fixed-window (a hard reset at the UTC-day boundary), for the same reason
 * D26 chose it for rate caps: an abuse backstop, not billing-grade metering.
 * Reads tolerate numeric STRINGS because DB-backed transients round-trip
 * through wp_options text columns (the RateCounter bug of 2026-07-07 —
 * an is_float() check here would silently zero every total across requests).
 *
 * Calls bare time() unqualified so the test suite's
 * Specflux\AgentSafety\Plugin\Support\time() override (tests/stubs/wpas-clock.php)
 * can freeze/advance "now"; production always falls through to the global.
 */
final class ValueAccumulator
{
    private const DAY_WINDOW = 86400;

    // TTL headroom beyond the window itself: the bucket must outlive the day
    // it sums (plus slack for transient eviction timing) — expiring early
    // would silently reopen a spent budget mid-day.
    private const DAY_TTL = self::DAY_WINDOW * 2;

    /**
     * The magnitudes already admitted in the current UTC-day window, keyed by
     * cap id — the $dayTotals input to ArgumentCapPolicy::evaluate().
     *
     * @param list<string> $capIds
     * @return array<string, float>
     */
    public function totalsFor(string $pack, string $token, array $capIds): array
    {
        $totals = [];
        foreach ($capIds as $capId) {
            $totals[$capId] = $this->read($this->key($pack, $token, $capId));
        }

        return $totals;
    }

    /**
     * Record an admitted call's magnitudes against the current day buckets.
     *
     * @param array<string, float> $amounts cap id => abs(value), from
     *                                      ArgumentCapPolicy::accumulableAmounts().
     */
    public function accumulate(string $pack, string $token, array $amounts): void
    {
        foreach ($amounts as $capId => $amount) {
            $key = $this->key($pack, $token, $capId);
            set_transient($key, (string) ($this->read($key) + $amount), self::DAY_TTL);
        }
    }

    private function read(string $key): float
    {
        $value = get_transient($key);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * Short, deterministic transient key: hashing (pack, token, cap id) keeps
     * it inside WordPress's transient name length limit whatever the real
     * identifiers look like; the day bucket keeps it unique per window.
     */
    private function key(string $pack, string $token, string $capId): string
    {
        return 'agsafe_vc_' . substr(md5($pack . '|' . $token . '|' . $capId), 0, 20)
            . '_d_' . intdiv(time(), self::DAY_WINDOW);
    }
}
