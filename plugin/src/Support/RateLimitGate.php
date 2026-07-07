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

    /**
     * One verdict per unique (pack, token, verb, args) within this request.
     *
     * The permission callback runs TWICE per executed call on a real install:
     * once from mcp-adapter's check_permission and again inside
     * WP_Ability::execute()'s unconditional re-check — and when both gate
     * seams are active a single MCP call consults this gate from each. Without
     * this memo one call consumed 2-3 quota units, and at calls_per_minute=1
     * the in-request re-check denied the very call it had just admitted
     * (found by live smoke test, 2026-07-07). Same pattern as
     * AbilityPermissionGate's approval-claim memo. Trade-off, documented:
     * genuinely distinct-but-identical calls (same verb AND same args) within
     * one request share a verdict and count once.
     *
     * @var array<string, string|null>
     */
    private array $admitted = [];

    public function __construct(
        private readonly RateCounter $counter = new RateCounter(),
        private readonly LimitPolicy $policy = new LimitPolicy(),
    ) {
    }

    /**
     * @param array<string, mixed> $args The call's arguments; part of the
     *                                   per-request memo key.
     * @return string|null The tripped limit name ("calls_per_minute" |
     *                      "calls_per_hour") when the call must be denied, or
     *                      null when it is admitted — in which case the
     *                      counter has been incremented for it exactly once
     *                      per unique call, however many times the host
     *                      re-checks permissions for that call.
     */
    public function admit(Pack $pack, ?string $token, string $verb, array $args): ?string
    {
        if (!$pack->hasRateLimits()) {
            return null;
        }

        $identity = $token ?? self::ANONYMOUS_TOKEN;
        $memoKey = $pack->name . '|' . $identity . '|' . $verb . '|' . md5(serialize($args));
        if (array_key_exists($memoKey, $this->admitted)) {
            return $this->admitted[$memoKey];
        }

        $check = $this->policy->evaluate($pack->limits, $this->counter->countsFor($pack->name, $identity));

        if (!$check->allowed) {
            return $this->admitted[$memoKey] = $check->trippedLimit;
        }

        $this->counter->increment($pack->name, $identity);

        return $this->admitted[$memoKey] = null;
    }
}
