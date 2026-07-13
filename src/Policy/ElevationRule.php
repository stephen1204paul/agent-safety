<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Policy;

/**
 * An arg-aware tier-elevation rule: a verb's BASE tier (from {@see
 * VerbCatalog}) can span more than one blast radius depending on its call args
 * (e.g. a status update that fires fulfillment, or a single delete vs. a bulk
 * one). A rule inspects one call and either elevates it to a higher tier, or
 * declines by returning null so the current tier stands.
 *
 * Rules only ever elevate; {@see TierClassifier} applies them in order and
 * never lets a rule lower a tier below what came before it.
 */
interface ElevationRule
{
    /**
     * @param array<string, mixed> $args
     * @return Tier|null An elevated tier, or null to leave $currentTier untouched.
     */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier;
}
