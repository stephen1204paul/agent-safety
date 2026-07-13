<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Policy;

/**
 * The catalog of verb -> tier assignments. OUR tier assignment is
 * authoritative and overrides any self-reported readonly/destructive
 * annotation: if an ability claims read-only but appears here as a write, the gate
 * fails closed.
 *
 * Instance-based registry: integrations (e.g. a WooCommerce module) {@see
 * register()} their own verb -> tier maps on top of whatever the host already
 * knows. An unknown verb returns null from {@see baseTier()} so callers fail
 * closed rather than trust an unclassified verb.
 *
 * Keyed by canonical verb id ("namespace/resource-action"). A key ending in "*"
 * (e.g. "woocommerce/reports-*") is a PREFIX pattern: it matches any verb that
 * starts with the text before the "*". Everything else is an exact match. Exact
 * matches win outright; among prefix patterns the longest match wins.
 */
final class VerbCatalog
{
    /** @var array<string, Tier> exact verb id => tier */
    private array $exact = [];

    /** @var array<string, Tier> "prefix-*" pattern => tier */
    private array $prefixed = [];

    /**
     * Merge a verb => tier map into the catalog. Later registrations overwrite
     * an earlier entry for the same exact key or prefix pattern; callers own
     * conflict resolution ("last registered wins" is left to the host).
     *
     * @param array<string, Tier> $verbToTier
     */
    public function register(array $verbToTier): void
    {
        foreach ($verbToTier as $verb => $tier) {
            if (str_ends_with($verb, '*')) {
                $this->prefixed[$verb] = $tier;
            } else {
                $this->exact[$verb] = $tier;
            }
        }
    }

    /** Base tier for a verb, or null if the verb is unknown (gate fails closed on null). */
    public function baseTier(string $verb): ?Tier
    {
        if (isset($this->exact[$verb])) {
            return $this->exact[$verb];
        }

        $best = null;
        $bestLen = -1;
        foreach ($this->prefixed as $pattern => $tier) {
            $prefix = rtrim($pattern, '*');
            if ($prefix !== '' && str_starts_with($verb, $prefix) && strlen($prefix) > $bestLen) {
                $best = $tier;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    public function isKnown(string $verb): bool
    {
        return $this->baseTier($verb) !== null;
    }
}
