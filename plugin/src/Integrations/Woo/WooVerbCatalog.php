<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Policy\Tier;

/**
 * OUR tier assignments for WooCommerce verbs, contributed into the
 * core {@see \Specflux\AgentSafety\Policy\VerbCatalog} by {@see WooIntegration::register()}.
 * This is authoritative and overrides any self-reported readonly/destructive
 * annotation: if a Woo ability claims read-only but appears here as a
 * write, the gate fails closed.
 */
final class WooVerbCatalog
{
    // Verb ids are the canonical WP Ability ids as registered by WooCommerce
    // (verified at runtime against Woo 10.8.1 + WP 7.0): "woocommerce/{resource}-{action}".
    // The MCP tool name is the hyphenated form "woocommerce-{resource}-{action}"; see VerbMapper.
    // Keys ending in "*" are prefix patterns (see VerbCatalog::register()).

    /** @var array<string, Tier> */
    public const MAP = [
        // The 9 abilities Woo core exposes today.
        'woocommerce/products-list'   => Tier::Reversible,
        'woocommerce/products-get'    => Tier::Reversible,
        'woocommerce/orders-list'     => Tier::Reversible,
        'woocommerce/orders-get'      => Tier::Reversible,
        'woocommerce/products-create' => Tier::SideEffecting,
        'woocommerce/products-update' => Tier::SideEffecting,
        'woocommerce/products-delete' => Tier::SideEffecting, // bulk elevates to Tier 2 (BulkProductDeleteElevationRule)
        'woocommerce/orders-create'   => Tier::SideEffecting,
        'woocommerce/orders-update'   => Tier::SideEffecting, // status->fulfillment elevates to Tier 2 (OrderFulfillmentElevationRule)

        // NOT exposed by Woo core 10.8.1 — mapped for forward-compat (extensions / future core
        // abilities). Kept so the gate fails CLOSED-with-intent rather than "unknown" if they appear.
        'woocommerce/orders-refund'   => Tier::Irreversible,  // cannot un-charge a card
        'woocommerce/customers-email' => Tier::Irreversible,  // cannot un-send

        // Forward-compat prefix patterns; none exposed by core 10.8.1.
        'woocommerce/reports-*'  => Tier::Reversible,    // read; aggregate
        'woocommerce/settings-*' => Tier::SideEffecting, // allowlist; sensitive keys gated/denied per pack
    ];
}
