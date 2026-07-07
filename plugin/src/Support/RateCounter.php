<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Fixed-window call counters backing a Pack's rate/quota caps (backlog #16;
 * SPEC's Policy Envelope: "rate/quota caps" per pack). Storage is WordPress
 * transients, keyed by (pack name, identity token, window bucket) — a fresh
 * bucket per calendar minute/hour, so two identities under the same pack (or
 * the same identity under two packs) never share a counter.
 *
 * Fixed-window, not sliding-window, by deliberate choice: a counter resets
 * hard at the bucket boundary rather than decaying continuously, so a burst
 * that lands right across a boundary can momentarily allow up to ~2x the
 * configured rate within that edge. This is an acceptable simplicity
 * trade-off for an abuse backstop — it is NOT precise enough for billing-grade
 * metering, which would need a sliding/leaky-bucket algorithm instead.
 *
 * Calls bare time() unqualified: PHP resolves an unqualified function call by
 * checking the CALLING code's own namespace first and only falls back to the
 * global one if nothing matches there. The test suite defines a
 * Specflux\AgentSafety\Plugin\Support\time() override (see
 * tests/stubs/wpas-clock.php) so tests can freeze/advance "now" via
 * $GLOBALS['wpas_test_time']; production code never loads that stub, so the
 * bare call here always falls through to the real global time().
 */
final class RateCounter
{
    private const MINUTE_WINDOW = 60;
    private const HOUR_WINDOW = 3600;

    // TTL headroom beyond the window itself: a bucket must outlive the window
    // it counts (plus slack for the transient's own eviction timing), never
    // less than it — expiring it early would silently reset the count mid-window.
    private const MINUTE_TTL = self::MINUTE_WINDOW * 2;
    private const HOUR_TTL = self::HOUR_WINDOW * 2;

    /** @return array{minute: int, hour: int} Calls already recorded in the current windows. */
    public function countsFor(string $pack, string $token): array
    {
        return [
            'minute' => $this->read($this->minuteKey($pack, $token)),
            'hour' => $this->read($this->hourKey($pack, $token)),
        ];
    }

    /** Record one more call against both the current minute and hour buckets. */
    public function increment(string $pack, string $token): void
    {
        $this->bump($this->minuteKey($pack, $token), self::MINUTE_TTL);
        $this->bump($this->hourKey($pack, $token), self::HOUR_TTL);
    }

    private function read(string $key): int
    {
        $value = get_transient($key);

        // DB-backed transients (the WordPress default, no object cache) come
        // back as STRINGS — get_option round-trips through wp_options text
        // columns. An is_int() check here silently zeroed the counter on every
        // fresh request, so caps never tripped across requests (found by live
        // smoke test, 2026-07-07). Only an object-cache-backed site returns
        // real ints. Accept both.
        return is_numeric($value) ? (int) $value : 0;
    }

    private function bump(string $key, int $ttl): void
    {
        set_transient($key, $this->read($key) + 1, $ttl);
    }

    private function minuteKey(string $pack, string $token): string
    {
        return 'agsafe_rl_' . $this->bucketId($pack, $token, 'm', self::MINUTE_WINDOW);
    }

    private function hourKey(string $pack, string $token): string
    {
        return 'agsafe_rl_' . $this->bucketId($pack, $token, 'h', self::HOUR_WINDOW);
    }

    /**
     * A short, deterministic transient-key suffix: hashing (pack, token) keeps
     * the key well within WordPress's transient name length limit regardless
     * of how long a real pack name or identity token (e.g. an application
     * password UUID) gets, while the window bucket keeps it unique per window.
     */
    private function bucketId(string $pack, string $token, string $window, int $windowSeconds): string
    {
        return substr(md5($pack . '|' . $token), 0, 20) . '_' . $window . '_' . intdiv(time(), $windowSeconds);
    }
}
