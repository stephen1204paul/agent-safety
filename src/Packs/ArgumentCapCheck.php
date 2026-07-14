<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * The verdict of {@see ArgumentCapPolicy::evaluate()}. Three shapes:
 *
 *   - allow():           every matching cap is satisfied.
 *   - deny(...):         a hard constraint tripped ($trippedCap names the
 *                        {@see ArgumentCap::$id}, $constraint names WHICH of
 *                        its fields: "max_per_call" | "max_total_per_day" |
 *                        "max_items_per_call" | "unreadable_argument"), so a
 *                        gate seam can name both in the denial reason and the
 *                        audit trail, exactly like {@see LimitCheck} does for
 *                        rate caps.
 *   - requireApproval(): only "approval_above" stands between this call and
 *                        Allow — the seam should route it through the normal
 *                        human-approval flow rather than deny.
 *
 * A deny always beats a require-approval: the policy keeps scanning after an
 * approval-threshold trip and reports any later hard denial instead.
 */
final class ArgumentCapCheck
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $requiresApproval,
        public readonly ?string $trippedCap,
        public readonly ?string $constraint,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, false, null, null);
    }

    public static function deny(string $trippedCap, string $constraint): self
    {
        return new self(false, false, $trippedCap, $constraint);
    }

    public static function requireApproval(string $trippedCap, string $constraint): self
    {
        return new self(false, true, $trippedCap, $constraint);
    }
}
