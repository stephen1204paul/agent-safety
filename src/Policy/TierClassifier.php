<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Policy;

/**
 * Resolves a verb (plus its call args) to a Tier, applying arg-aware
 * elevation rules where a single verb spans blast radii.
 *
 * Framework-agnostic and integration-agnostic: the verb catalog and the
 * elevation rules are both injected, so this class carries no knowledge of any
 * particular plugin's verbs (e.g. WooCommerce's) — those are contributed by an
 * integration module at the host's bootstrap.
 */
final class TierClassifier
{
    /** @var list<ElevationRule> */
    private array $rules;

    /**
     * @param list<ElevationRule> $elevationRules Applied in order; each sees the
     *                                             tier as elevated by the ones before it.
     */
    public function __construct(
        private readonly VerbCatalog $catalog = new VerbCatalog(),
        array $elevationRules = [],
    ) {
        $this->rules = $elevationRules;
    }

    /**
     * @param array<string, mixed> $args
     * @return Tier|null Null when the verb is unknown (caller must fail closed).
     */
    public function classify(string $verb, array $args = []): ?Tier
    {
        $tier = $this->catalog->baseTier($verb);
        if ($tier === null) {
            return null;
        }

        foreach ($this->rules as $rule) {
            $elevated = $rule->apply($verb, $args, $tier);
            // A rule may only ever RAISE the tier. The interface has always said
            // so, but until AS-12 nothing enforced it and a rule returning a
            // LOWER tier silently won — which would let one buggy or hostile rule
            // demote an irreversible verb out of the approval gate entirely.
            // Enforcing it here rather than trusting each rule is what makes it
            // safe to accept rules from outside the bundled integration modules
            // (`agent_safety_elevation_rules`): the seam can narrow, never widen.
            if ($elevated !== null && $elevated->value > $tier->value) {
                $tier = $elevated;
            }
        }

        return $tier;
    }

    /**
     * Fail-closed guard for self-reported annotations: an ability that
     * claims read-only while OUR catalog classifies it as a write is a lie or
     * a misconfiguration — refuse to trust it.
     *
     * @param array<string, mixed> $args
     */
    public function isReadonlyButWrites(string $verb, bool $selfReportedReadonly, array $args = []): bool
    {
        if (!$selfReportedReadonly) {
            return false;
        }
        $tier = $this->classify($verb, $args);

        return $tier !== null && $tier->isWrite();
    }
}
