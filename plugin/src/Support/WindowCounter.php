<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * A fixed-window atomic counter for any subject string — the bucket scheme
 * of {@see RateCounter}, which is specialised to a pack's minute and hour
 * caps, made general for the tripwires ({@see Tripwires}): one bucket per
 * window of $windowSeconds since the epoch, keyed by a hash of the subject so
 * a long subject never exceeds the key column width, TTL twice the window
 * so a bucket outlives what it counts. Storage is {@see AtomicCounterStore},
 * so {@see increment()}/{@see decrement()} are single atomic statements and a
 * caller can increment first, check the returned count, and decrement to
 * refuse.
 *
 * Fixed-window, so a burst straddling a bucket edge can reach ~2x a threshold
 * momentarily; acceptable for a tripwire, not for metering. Bare time() on
 * purpose, for the same test clock.
 */
final class WindowCounter
{
    // Namespaces this counter's rows in the shared counters table (the key is
    // per-subject, so no fixed list of full names exists).
    public const PREFIX = 'agsafe_win_';

    public function __construct(private readonly AtomicCounterStore $store = new AtomicCounterStore())
    {
    }

    /** Events already recorded for $subject in the current window. */
    public function count(string $subject, int $windowSeconds): int
    {
        return (int) round($this->store->get($this->key($subject, $windowSeconds)));
    }

    /** Atomically record one more event for $subject; returns the window's new count. */
    public function increment(string $subject, int $windowSeconds): int
    {
        return (int) round($this->store->add($this->key($subject, $windowSeconds), 1.0, $windowSeconds * 2));
    }

    /** Atomically undo one {@see increment()}; returns the window's new count. */
    public function decrement(string $subject, int $windowSeconds): int
    {
        return (int) round($this->store->add($this->key($subject, $windowSeconds), -1.0, $windowSeconds * 2));
    }

    private function key(string $subject, int $windowSeconds): string
    {
        return self::PREFIX . substr(md5($subject), 0, 20) . '_' . $windowSeconds . '_' . intdiv(time(), $windowSeconds);
    }
}
