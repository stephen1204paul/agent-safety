<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * AS-8 (§3.5 item 6): `agent-safety/check-approval`'s OWN fixed rate limit —
 * 10 calls/minute per principal, through the existing {@see RateCounter}
 * under a reserved key that no Pack owns, independent of the caller's Pack
 * (whose own `calls_per_minute`/`calls_per_hour` caps, enforced by
 * {@see RateLimitGate}, never apply to this verb — see
 * {@see \Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline::judgeCheckApproval()}).
 *
 * Lives in this namespace (not Integrations\Self) so its retry-after
 * calculation shares {@see RateCounter}'s bare `time()` call and the same
 * test clock override (tests/stubs/wpas-clock.php).
 */
final class SelfRateLimit
{
    /** Reserved {@see RateCounter} bucket name: no Pack is ever named this. */
    private const KEY = 'agent-safety-self';

    private const PER_MINUTE = 10;

    public function __construct(private readonly RateCounter $counter = new RateCounter())
    {
    }

    /**
     * True when this call is admitted (and has just been counted). False —
     * without counting it further — when $identity has already made
     * {@see PER_MINUTE} calls in the current minute window.
     */
    public function admit(string $identity): bool
    {
        if ($this->counter->countsFor(self::KEY, $identity)['minute'] >= self::PER_MINUTE) {
            return false;
        }

        $this->counter->increment(self::KEY, $identity);

        return true;
    }

    /** Seconds until the current fixed minute window resets. */
    public function retryAfterSeconds(): int
    {
        return 60 - (time() % 60);
    }
}
