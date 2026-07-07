<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Packs\LimitPolicy;
use Specflux\AgentSafety\Packs\Pack;

/**
 * Glues the core's pure {@see LimitPolicy} to the host's {@see RateCounter}
 * storage. Shared by BOTH gate seams ({@see \Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate}
 * and {@see \Specflux\AgentSafety\Plugin\Hooks\PreToolCallGate}) for the same
 * reason {@see DecisionRecorder} is shared: a pack's rate/quota caps must be
 * enforced identically no matter which seam intercepts a call first.
 *
 * {@see admit()} is only ever meant to be consulted for a decision that is
 * OTHERWISE Allow — a denial must never consume quota (a blocked retry is
 * free to retry once whatever blocked it clears). A pack with no configured
 * limits short-circuits before any storage read (SPEC: unlimited is the
 * common case for builtin packs).
 */
final class RateLimitGate
{
    /** Bucket used for a call with no resolvable identity token. */
    private const ANONYMOUS_TOKEN = '(anonymous)';

    public function __construct(
        private readonly RateCounter $counter = new RateCounter(),
        private readonly LimitPolicy $policy = new LimitPolicy(),
    ) {
    }

    /**
     * @return string|null The tripped limit name ("calls_per_minute" |
     *                      "calls_per_hour") when the call must be denied, or
     *                      null when it is admitted — in which case the
     *                      counter has ALREADY been incremented for it, so
     *                      callers must not call this twice for one call.
     */
    public function admit(Pack $pack, ?string $token): ?string
    {
        if (!$pack->hasRateLimits()) {
            return null;
        }

        $identity = $token ?? self::ANONYMOUS_TOKEN;
        $check = $this->policy->evaluate($pack->limits, $this->counter->countsFor($pack->name, $identity));

        if (!$check->allowed) {
            return $check->trippedLimit;
        }

        $this->counter->increment($pack->name, $identity);

        return null;
    }
}
