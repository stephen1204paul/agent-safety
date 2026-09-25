<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * A fixed-window transient counter for any subject string — the bucket scheme
 * of {@see RateCounter}, which is specialised to a pack's minute and hour
 * caps, made general for the tripwires ({@see Tripwires}): one bucket per
 * window of $windowSeconds since the epoch, keyed by a hash of the subject so
 * a long subject never exceeds the transient name limit, TTL twice the window
 * so a bucket outlives what it counts.
 *
 * Fixed-window, so a burst straddling a bucket edge can reach ~2x a threshold
 * momentarily; acceptable for a tripwire, not for metering. Reads accept the
 * numeric strings DB-backed transients hand back (the 2026-07-07 lesson in
 * RateCounter). Bare time() on purpose, for the same test clock.
 */
final class WindowCounter
{
    // Public: named by {@see UninstallManifest} as the transient-key prefix
    // an opted-in uninstall must sweep (this key is per-subject, so no fixed
    // list of full names exists).
    public const PREFIX = 'agsafe_win_';

    /** Events already recorded for $subject in the current window. */
    public function count(string $subject, int $windowSeconds): int
    {
        return $this->read($this->key($subject, $windowSeconds));
    }

    /** Record one more event for $subject; returns the window's new count. */
    public function increment(string $subject, int $windowSeconds): int
    {
        $key = $this->key($subject, $windowSeconds);
        $count = $this->read($key) + 1;
        set_transient($key, $count, $windowSeconds * 2);

        return $count;
    }

    private function read(string $key): int
    {
        $value = get_transient($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function key(string $subject, int $windowSeconds): string
    {
        return self::PREFIX . substr(md5($subject), 0, 20) . '_' . $windowSeconds . '_' . intdiv(time(), $windowSeconds);
    }
}
