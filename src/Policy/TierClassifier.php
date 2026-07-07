<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Policy;

/**
 * Resolves a verb (plus its call args) to a Tier, applying arg-aware
 * elevation rules where a single verb spans blast radii (SPEC §2).
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
            if ($elevated !== null) {
                $tier = $elevated;
            }
        }

        return $tier;
    }

    /**
     * Fail-closed guard for self-reported annotations (D3): an ability that
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
