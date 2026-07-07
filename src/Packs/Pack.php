<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

use Specflux\AgentSafety\Policy\Tier;

/**
 * A Capability Pack: a credentialed, purpose-scoped view of the verb catalog
 * (SPEC §3). Enforced in the gate, NOT via WP roles. A pack that denies a tier
 * class is injection-proof against that class by construction (D9).
 */
final class Pack
{
    /**
     * @param string         $name
     * @param list<string>   $allow      Verb glob patterns, e.g. ["woo/products.*", "*"].
     * @param list<string>   $denyClass  Tier class slugs hard-walled, e.g. ["tier2"].
     * @param array<string,bool> $approvalByClass Tier class slug => requires human approval.
     * @param string         $pii        "redacted" | "full".
     * @param array{calls_per_minute?: int|null, calls_per_hour?: int|null} $limits
     *     Pack-level rate/quota caps (the "Policy Envelope"'s rate/quota caps, per
     *     CONVERSATION-LOG.md): a fixed-window cap on calls made under this pack by
     *     one identity token, independent of which verb is called. A null value, or
     *     an absent key, means unlimited for that window. Enforced by the host (see
     *     {@see \Specflux\AgentSafety\Packs\LimitPolicy} for the pure evaluator and
     *     the plugin's `RateCounter`/`RateLimitGate` for the transient-backed
     *     counting) — this class only carries the config, never the counts. Builtin
     *     packs (owner, default-agent) ship unlimited; the shape exists for site
     *     owners/integrations that bind a specific credential to a capped pack.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $allow,
        public readonly array $denyClass = [],
        public readonly array $approvalByClass = [],
        public readonly string $pii = 'redacted',
        public readonly array $limits = [],
    ) {
    }

    /** True when this pack carries at least one rate/quota cap to enforce. */
    public function hasRateLimits(): bool
    {
        return ($this->limits['calls_per_minute'] ?? null) !== null
            || ($this->limits['calls_per_hour'] ?? null) !== null;
    }

    /** Does any allow-glob admit this verb? */
    public function allows(string $verb): bool
    {
        foreach ($this->allow as $pattern) {
            if (self::globMatch($pattern, $verb)) {
                return true;
            }
        }

        return false;
    }

    /** Is this tier class hard-denied (the injection-proof wall)? */
    public function deniesClass(Tier $tier): bool
    {
        return in_array($tier->classSlug(), $this->denyClass, true);
    }

    public function requiresApproval(Tier $tier): bool
    {
        return $this->approvalByClass[$tier->classSlug()] ?? false;
    }

    public function redactsPii(): bool
    {
        return $this->pii === 'redacted';
    }

    /** Treat "*" as match-anything; everything else is a literal with "*" wildcards. */
    private static function globMatch(string $pattern, string $subject): bool
    {
        if ($pattern === '*') {
            return true;
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

        return (bool) preg_match($regex, $subject);
    }
}
