<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Policy;

/**
 * Blast-radius classification for an agent verb (D5).
 *
 * Tier 0 — reversible / read-only.
 * Tier 1 — locally reversible / side-effecting (inverse-op or field revert).
 * Tier 2 — irreversible (cannot un-charge a card, un-send an email,
 *          un-fire fulfillment). Approval-gated.
 */
enum Tier: int
{
    case Reversible = 0;
    case SideEffecting = 1;
    case Irreversible = 2;

    /** Stable slug used by Capability Pack `deny_class` rules, e.g. "tier2". */
    public function classSlug(): string
    {
        return 'tier' . $this->value;
    }

    /** True for any verb that mutates store state (Tier >= 1). */
    public function isWrite(): bool
    {
        return $this !== self::Reversible;
    }
}
