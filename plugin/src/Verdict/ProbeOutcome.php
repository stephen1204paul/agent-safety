<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Verdict;

/**
 * What {@see VerdictPipeline}'s probe step found for one call: no probe is
 * declared for this verb (`kind = none`, unfingerprinted, behaviour
 * unchanged from before AS-6); the probe ran and produced a fingerprint
 * (`kind = probe`); or the probe threw or returned null, meaning the call
 * must be refused `state_unverifiable` rather than parked or claimed.
 */
final class ProbeOutcome
{
    private function __construct(
        public readonly bool $failed,
        public readonly string $kind,
        public readonly ?string $fingerprint,
        public readonly ?string $probeArgs = null,
    ) {
    }

    public static function none(): self
    {
        return new self(false, 'none', null);
    }

    /** $probeArgs is the canonical-JSON encoding of the probe's {@see \Specflux\AgentSafety\Plugin\Approval\StateProbe::targetArgs()}. */
    public static function ok(string $fingerprint, string $probeArgs): self
    {
        return new self(false, 'probe', $fingerprint, $probeArgs);
    }

    public static function failed(): self
    {
        return new self(true, 'probe', null);
    }
}
