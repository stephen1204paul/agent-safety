<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Fixed-window call counters backing a Pack's rate/quota caps (backlog #16;
 * the caps are declared per pack in its policy envelope). Storage is the atomic
 * {@see AtomicCounterStore}, keyed by (pack name, identity token, window
 * bucket) — a fresh bucket per calendar minute/hour, so two identities under
 * the same pack (or the same identity under two packs) never share a counter.
 *
 * Callers {@see reserve()} a slot first and check the counts it returns, then
 * {@see release()} it if the call is refused, so the check is made against a
 * total that already includes the call and concurrent requests cannot both
 * slip under a cap.
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
    // Namespaces this counter's rows in the shared counters table (the key is
    // per pack/identity/window, so no fixed list of full names exists).
    public const PREFIX = 'agsafe_rl_';

    private const MINUTE_WINDOW = 60;
    private const HOUR_WINDOW = 3600;

    // TTL headroom beyond the window itself: a bucket must outlive the window
    // it counts (plus slack for clock skew between requests), never
    // less than it — expiring it early would silently reset the count mid-window.
    private const MINUTE_TTL = self::MINUTE_WINDOW * 2;
    private const HOUR_TTL = self::HOUR_WINDOW * 2;

    public function __construct(private readonly AtomicCounterStore $store = new AtomicCounterStore())
    {
    }

    /** @return array{minute: int, hour: int} Calls already recorded in the current windows. */
    public function countsFor(string $pack, string $token): array
    {
        return [
            'minute' => (int) round($this->store->get($this->minuteKey($pack, $token))),
            'hour' => (int) round($this->store->get($this->hourKey($pack, $token))),
        ];
    }

    /**
     * Atomically record one more call against both the current minute and hour
     * buckets and return the counts INCLUDING it.
     *
     * @return array{minute: int, hour: int}
     */
    public function reserve(string $pack, string $token): array
    {
        return [
            'minute' => (int) round($this->store->add($this->minuteKey($pack, $token), 1.0, self::MINUTE_TTL)),
            'hour' => (int) round($this->store->add($this->hourKey($pack, $token), 1.0, self::HOUR_TTL)),
        ];
    }

    /** Undo one {@see reserve()} (the call was refused after all). */
    public function release(string $pack, string $token): void
    {
        $this->store->add($this->minuteKey($pack, $token), -1.0, self::MINUTE_TTL);
        $this->store->add($this->hourKey($pack, $token), -1.0, self::HOUR_TTL);
    }

    private function minuteKey(string $pack, string $token): string
    {
        return self::PREFIX . $this->bucketId($pack, $token, 'm', self::MINUTE_WINDOW);
    }

    private function hourKey(string $pack, string $token): string
    {
        return self::PREFIX . $this->bucketId($pack, $token, 'h', self::HOUR_WINDOW);
    }

    /**
     * A short, deterministic counter-key suffix: hashing (pack, token) keeps
     * the key well within the 64-char key column regardless
     * of how long a real pack name or identity token (e.g. an application
     * password UUID) gets, while the window bucket keeps it unique per window.
     */
    private function bucketId(string $pack, string $token, string $window, int $windowSeconds): string
    {
        return substr(md5($pack . '|' . $token), 0, 20) . '_' . $window . '_' . intdiv(time(), $windowSeconds);
    }
}
