<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Fixed-window day totals backing a Pack's argument-aware caps (roadmap 0.2
 * "spend limits"): the sums {@see \Specflux\AgentSafety\Packs\ArgumentCapPolicy}
 * evaluates `max_total_per_day` against. Storage is the atomic
 * {@see AtomicCounterStore}, keyed by (pack name, identity token, cap id,
 * UTC-day bucket) — the same design as {@see RateCounter}, summing float
 * magnitudes instead of counting calls, and likewise reserve-then-check:
 * {@see reserve()} adds a call's amounts atomically and returns the new
 * totals, {@see release()} takes them back when the call is refused.
 *
 * Fixed-window (a hard reset at the UTC-day boundary), for the same reason
 * D26 chose it for rate caps: an abuse backstop, not billing-grade metering.
 *  *
 * Calls bare time() unqualified so the test suite's
 * Specflux\AgentSafety\Plugin\Support\time() override (tests/stubs/wpas-clock.php)
 * can freeze/advance "now"; production always falls through to the global.
 */
final class ValueAccumulator
{
    // Namespaces this counter's rows in the shared counters table (the key is
    // per pack/identity/cap/day, so no fixed list of full names exists).
    public const PREFIX = 'agsafe_vc_';

    private const DAY_WINDOW = 86400;

    // TTL headroom beyond the window itself: the bucket must outlive the day
    // it sums (plus slack for clock skew between requests) — expiring early
    // would silently reopen a spent budget mid-day.
    private const DAY_TTL = self::DAY_WINDOW * 2;

    public function __construct(private readonly AtomicCounterStore $store = new AtomicCounterStore())
    {
    }

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
            $totals[$capId] = $this->store->get($this->key($pack, $token, $capId));
        }

        return $totals;
    }

    /**
     * Atomically add a call's magnitudes to the current day buckets and return
     * the totals INCLUDING them.
     *
     * @param array<string, float> $amounts cap id => abs(value), from
     *                                      ArgumentCapPolicy::accumulableAmounts().
     * @return array<string, float> cap id => new day total
     */
    public function reserve(string $pack, string $token, array $amounts): array
    {
        $totals = [];
        foreach ($amounts as $capId => $amount) {
            $totals[$capId] = $this->store->add($this->key($pack, $token, $capId), $amount, self::DAY_TTL);
        }

        return $totals;
    }

    /**
     * Undo a {@see reserve()} (the call was refused after all).
     *
     * @param array<string, float> $amounts the same map that was reserved.
     */
    public function release(string $pack, string $token, array $amounts): void
    {
        foreach ($amounts as $capId => $amount) {
            $this->store->add($this->key($pack, $token, $capId), -$amount, self::DAY_TTL);
        }
    }

    /**
     * Short, deterministic counter key: hashing (pack, token, cap id) keeps
     * it inside the 64-char key column whatever the real
     * identifiers look like; the day bucket keeps it unique per window.
     */
    private function key(string $pack, string $token, string $capId): string
    {
        return self::PREFIX . substr(md5($pack . '|' . $token . '|' . $capId), 0, 20)
            . '_d_' . intdiv(time(), self::DAY_WINDOW);
    }
}
