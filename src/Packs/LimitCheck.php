<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * The verdict of {@see LimitPolicy::evaluate()}: whether the NEXT call may
 * proceed under a pack's rate/quota caps, and — when it may not — which
 * configured limit tripped ("calls_per_minute" | "calls_per_hour"), so a gate
 * seam can name it in the denial reason and the audit trail.
 */
final class LimitCheck
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $trippedLimit,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, null);
    }

    public static function deny(string $trippedLimit): self
    {
        return new self(false, $trippedLimit);
    }
}
