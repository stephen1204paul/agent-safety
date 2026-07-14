<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * Pure evaluator for a Pack's argument-aware caps ({@see ArgumentCap};
 * roadmap 0.2 "spend limits"). Given the pack's declared caps, the verb and
 * arguments of the call under evaluation, and the day-window totals the host
 * has already recorded, decides whether the NEXT call may proceed.
 *
 * No I/O, no clock, no WordPress — the same division of labour as
 * {@see LimitPolicy}: the host (the plugin's `ValueAccumulator`) owns
 * summing and storage; this class owns only the arithmetic, so it is
 * exhaustively unit-testable.
 *
 * Ordering rules, all fail-closed:
 *   - Caps are scanned in declaration order; the FIRST hard denial wins.
 *   - A hard denial anywhere beats an approval-threshold trip anywhere: the
 *     scan continues past a tripped $approvalAbove looking for denials, and
 *     only reports require-approval when none is found. (Routing a call that
 *     would ANYWAY be denied into the approval queue would invite a human to
 *     approve something the policy can never admit.)
 *   - An unreadable governed argument — missing, non-numeric where a value
 *     constraint applies, non-array where an item-count constraint applies —
 *     denies the call. If the cap governs a value the call refuses to
 *     present legibly, the call does not run.
 *   - Values compare by magnitude (absolute value): signed inputs must not
 *     drive a daily total down or duck under a threshold.
 */
final class ArgumentCapPolicy
{
    /**
     * @param list<ArgumentCap>    $caps      The pack's declared caps ({@see Pack::$argumentCaps}).
     * @param array<string, mixed> $args      The call's arguments.
     * @param array<string, float> $dayTotals Magnitudes already admitted in the current
     *                                        UTC-day window, keyed by cap id, BEFORE the
     *                                        call under evaluation.
     * @param bool $hasValidApproval          True when the call already carries a claimed
     *                                        human grant for this exact verb+args — a
     *                                        tripped $approvalAbove is then satisfied,
     *                                        while every hard constraint still applies.
     */
    public function evaluate(
        array $caps,
        string $verb,
        array $args,
        array $dayTotals,
        bool $hasValidApproval = false,
    ): ArgumentCapCheck {
        $needsApproval = null;

        foreach ($caps as $cap) {
            if (!$cap->appliesTo($verb)) {
                continue;
            }

            $value = self::resolve($args, $cap->argPath);

            if ($cap->maxItemsPerCall !== null) {
                if (!is_array($value)) {
                    return ArgumentCapCheck::deny($cap->id, 'unreadable_argument');
                }
                if (count($value) > $cap->maxItemsPerCall) {
                    return ArgumentCapCheck::deny($cap->id, 'max_items_per_call');
                }
            }

            if (!$cap->readsValue()) {
                continue;
            }

            if (!is_numeric($value)) {
                return ArgumentCapCheck::deny($cap->id, 'unreadable_argument');
            }
            $magnitude = abs((float) $value);

            if ($cap->maxPerCall !== null && $magnitude > $cap->maxPerCall) {
                return ArgumentCapCheck::deny($cap->id, 'max_per_call');
            }

            if (
                $cap->maxTotalPerDay !== null
                && ($dayTotals[$cap->id] ?? 0.0) + $magnitude > $cap->maxTotalPerDay
            ) {
                return ArgumentCapCheck::deny($cap->id, 'max_total_per_day');
            }

            if (
                $needsApproval === null
                && !$hasValidApproval
                && $cap->approvalAbove !== null
                && $magnitude > $cap->approvalAbove
            ) {
                $needsApproval = ArgumentCapCheck::requireApproval($cap->id, 'approval_above');
            }
        }

        return $needsApproval ?? ArgumentCapCheck::allow();
    }

    /**
     * The magnitudes an ADMITTED call must add to the day-window totals:
     * cap id => abs(value), for every cap that matches the verb, accumulates
     * ({@see ArgumentCap::accumulates()}), and can read its argument. Kept
     * here so the host's accumulator applies the exact dot-path and
     * magnitude rules {@see evaluate()} enforced.
     *
     * @param list<ArgumentCap>    $caps
     * @param array<string, mixed> $args
     * @return array<string, float>
     */
    public function accumulableAmounts(array $caps, string $verb, array $args): array
    {
        $amounts = [];
        foreach ($caps as $cap) {
            if (!$cap->accumulates() || !$cap->appliesTo($verb)) {
                continue;
            }
            $value = self::resolve($args, $cap->argPath);
            if (is_numeric($value)) {
                $amounts[$cap->id] = abs((float) $value);
            }
        }

        return $amounts;
    }

    /**
     * Walk a dot-notation path ("refund.total") into the call args. Returns
     * null when any segment is missing or the walk hits a non-array — the
     * caller treats null as unreadable, and a legitimately-null argument is
     * equally unreadable for every constraint this policy enforces.
     *
     * @param array<string, mixed> $args
     */
    private static function resolve(array $args, string $path): mixed
    {
        $current = $args;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}
