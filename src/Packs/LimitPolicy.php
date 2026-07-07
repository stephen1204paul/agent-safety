<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * Pure evaluator for a Pack's rate/quota caps (backlog #16; the "Policy
 * Envelope"'s rate/quota caps, per CONVERSATION-LOG.md). Given a pack's
 * configured {@see Pack::$limits} and the caller's call counts already
 * recorded in the current fixed windows, decides whether the NEXT call may
 * proceed.
 *
 * No I/O, no clock, no WordPress: the host (the plugin's `RateCounter`) owns
 * counting and storage; this class owns only the allow/deny arithmetic, so it
 * is exhaustively unit-testable like the rest of the core.
 *
 * A null (or absent) limit means unlimited for that window. When the next call
 * would trip both windows at once, the tighter per-minute window is reported —
 * it is the one that will clear soonest, which is the more actionable of the
 * two to surface in a denial reason or audit record.
 */
final class LimitPolicy
{
    /**
     * @param array{calls_per_minute?: int|null, calls_per_hour?: int|null} $limits
     * @param array{minute: int, hour: int} $counts Calls already recorded in the
     *                                                current windows, BEFORE the
     *                                                call under evaluation.
     */
    public function evaluate(array $limits, array $counts): LimitCheck
    {
        $perMinute = $limits['calls_per_minute'] ?? null;
        if ($perMinute !== null && $counts['minute'] >= $perMinute) {
            return LimitCheck::deny('calls_per_minute');
        }

        $perHour = $limits['calls_per_hour'] ?? null;
        if ($perHour !== null && $counts['hour'] >= $perHour) {
            return LimitCheck::deny('calls_per_hour');
        }

        return LimitCheck::allow();
    }
}
