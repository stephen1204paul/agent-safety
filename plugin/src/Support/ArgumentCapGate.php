<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\ArgumentCapCheck;
use Specflux\AgentSafety\Packs\ArgumentCapPolicy;
use Specflux\AgentSafety\Packs\Pack;

/**
 * Glues the core's pure {@see ArgumentCapPolicy} to the host's
 * {@see ValueAccumulator} storage — the argument-aware sibling of
 * {@see RateLimitGate}, and shared by BOTH gate seams for the same reason:
 * a pack's spend limits must bind identically no matter which seam
 * intercepts a call first.
 *
 * {@see check()} is only ever meant to be consulted for a decision that is
 * OTHERWISE Allow (after tier gating and rate caps). Only an ADMITTED call
 * accumulates into the day totals — a denied or approval-parked call never
 * consumes budget, so it is free to retry once whatever blocked it clears
 * (D26's rule, extended to sums).
 */
final class ArgumentCapGate
{
    /** Bucket used for a call with no resolvable identity token. */
    private const ANONYMOUS_TOKEN = '(anonymous)';

    /**
     * One verdict per unique (pack, token, verb, args, approval-state) within
     * this request — the same re-check problem RateLimitGate memoizes against
     * (the permission callback runs 2-3x per executed call), with the same
     * documented trade-off: identical calls within one request share a
     * verdict and accumulate once. The approval flag is part of the key
     * because the ability seam legitimately re-checks the SAME call after
     * claiming a human grant, and that second check must not replay the
     * pre-claim verdict.
     *
     * @var array<string, ArgumentCapCheck>
     */
    private array $verdicts = [];

    public function __construct(
        private readonly ValueAccumulator $accumulator = new ValueAccumulator(),
        private readonly ArgumentCapPolicy $policy = new ArgumentCapPolicy(),
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @param bool $hasValidApproval True when the call already carries a
     *                               claimed human grant for this exact
     *                               verb+args (satisfies approval_above;
     *                               hard caps still apply).
     */
    public function check(
        Pack $pack,
        ?string $token,
        string $verb,
        array $args,
        bool $hasValidApproval = false,
    ): ArgumentCapCheck {
        if (!$pack->hasArgumentCaps()) {
            return ArgumentCapCheck::allow();
        }

        $matching = array_values(array_filter(
            $pack->argumentCaps,
            static fn (ArgumentCap $cap): bool => $cap->appliesTo($verb),
        ));
        if ($matching === []) {
            return ArgumentCapCheck::allow();
        }

        $identity = $token ?? self::ANONYMOUS_TOKEN;
        $memoKey = $pack->name . '|' . $identity . '|' . $verb . '|' . md5(serialize($args))
            . '|' . ($hasValidApproval ? '1' : '0');
        if (isset($this->verdicts[$memoKey])) {
            return $this->verdicts[$memoKey];
        }

        $accumulating = array_values(array_map(
            static fn (ArgumentCap $cap): string => $cap->id,
            array_filter($matching, static fn (ArgumentCap $cap): bool => $cap->accumulates()),
        ));

        $check = $this->policy->evaluate(
            $matching,
            $verb,
            $args,
            $accumulating === [] ? [] : $this->accumulator->totalsFor($pack->name, $identity, $accumulating),
            $hasValidApproval,
        );

        if ($check->allowed) {
            $amounts = $this->policy->accumulableAmounts($matching, $verb, $args);
            if ($amounts !== []) {
                $this->accumulator->accumulate($pack->name, $identity, $amounts);
            }
        }

        return $this->verdicts[$memoKey] = $check;
    }
}
