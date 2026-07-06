<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Policy;

/**
 * OUR tier assignments for Woo verbs (SPEC §2). This is authoritative and
 * overrides any self-reported readonly/destructive annotation (D3): if an
 * ability claims read-only but appears here as a write, the gate fails closed.
 *
 * Keyed by canonical verb id ("namespace/resource.action"). Exact matches win;
 * otherwise the longest matching "prefix.*" pattern applies.
 */
final class VerbCatalog
{
    // Verb ids are the canonical WP Ability ids as registered by WooCommerce
    // (verified at runtime against Woo 10.8.1 + WP 7.0): "woocommerce/{resource}-{action}".
    // The MCP tool name is the hyphenated form "woocommerce-{resource}-{action}"; see VerbMapper.

    /** @var array<string, Tier> exact verb => tier. The 9 abilities Woo core exposes today. */
    private const EXACT = [
        'woocommerce/products-list'   => Tier::Reversible,
        'woocommerce/products-get'    => Tier::Reversible,
        'woocommerce/orders-list'     => Tier::Reversible,
        'woocommerce/orders-get'      => Tier::Reversible,
        'woocommerce/products-create' => Tier::SideEffecting,
        'woocommerce/products-update' => Tier::SideEffecting,
        'woocommerce/products-delete' => Tier::SideEffecting, // bulk elevates to Tier 2 (TierClassifier)
        'woocommerce/orders-create'   => Tier::SideEffecting,
        'woocommerce/orders-update'   => Tier::SideEffecting, // status->fulfillment elevates to Tier 2

        // NOT exposed by Woo core 10.8.1 — mapped for forward-compat (extensions / future core
        // abilities). Kept so the gate fails CLOSED-with-intent rather than "unknown" if they appear.
        'woocommerce/orders-refund'   => Tier::Irreversible,  // cannot un-charge a card
        'woocommerce/customers-email' => Tier::Irreversible,  // cannot un-send
    ];

    /** @var array<string, Tier> "prefix" (resource group) => tier. Forward-compat; none in core 10.8.1. */
    private const PREFIX = [
        'woocommerce/reports'  => Tier::Reversible,    // read; aggregate
        'woocommerce/settings' => Tier::SideEffecting, // allowlist; sensitive keys gated/denied per pack
    ];

    /** Base tier for a verb, or null if the verb is unknown (gate fails closed on null). */
    public static function baseTier(string $verb): ?Tier
    {
        if (isset(self::EXACT[$verb])) {
            return self::EXACT[$verb];
        }

        $best = null;
        $bestLen = -1;
        foreach (self::PREFIX as $prefix => $tier) {
            if (str_starts_with($verb, $prefix . '-') && strlen($prefix) > $bestLen) {
                $best = $tier;
                $bestLen = strlen($prefix);
            }
        }

        return $best;
    }

    public static function isKnown(string $verb): bool
    {
        return self::baseTier($verb) !== null;
    }
}
