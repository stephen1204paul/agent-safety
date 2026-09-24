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
    // (verified at runtime against WooCommerce 11.1.0 + WP 7.0): "woocommerce/{resource}-{action}".
    // The MCP tool name is the hyphenated form "woocommerce-{resource}-{action}"; see VerbMapper.
    // Keys ending in "*" are prefix patterns (see VerbCatalog::register()).
    // These are the only 16 named abilities; anything else under "woocommerce/"
    // is refused as unknown_verb.

    /** @var array<string, Tier> */
    public const MAP = [
        // The 9 abilities Woo core exposes today.
        'woocommerce/products-list'   => Tier::Reversible,
        'woocommerce/products-get'    => Tier::Reversible,
        'woocommerce/orders-list'     => Tier::Reversible,
        'woocommerce/orders-get'      => Tier::Reversible,
        'woocommerce/products-create' => Tier::SideEffecting,
        'woocommerce/products-update' => Tier::SideEffecting,
        'woocommerce/products-delete' => Tier::SideEffecting, // bulk or force=true elevates to Tier 2 (BulkProductDelete/ForceDeleteElevationRule)
        'woocommerce/orders-create'   => Tier::SideEffecting,
        'woocommerce/orders-update'   => Tier::SideEffecting, // status->fulfillment/cancelled/refunded elevates to Tier 2 (OrderFulfillmentElevationRule)

        // The 7 session-visible abilities Woo core exposes to the MCP/Abilities
        // session surface (distinct ids from the 9 MCP-bridge names above —
        // verified against WooCommerce 11.1.0 + WP 7.0).
        'woocommerce/orders-query'        => Tier::Reversible,
        'woocommerce/products-query'      => Tier::Reversible,
        'woocommerce/product-create'      => Tier::SideEffecting, // regular_price/sale_price or status publish/future elevates to Tier 2 (ProductPriceOrPublishElevationRule)
        'woocommerce/product-update'      => Tier::SideEffecting, // regular_price/sale_price or status publish/future elevates to Tier 2 (ProductPriceOrPublishElevationRule)
        'woocommerce/order-add-note'      => Tier::SideEffecting, // customer_note=true elevates to Tier 2 (CustomerNoteElevationRule)
        'woocommerce/order-update-status' => Tier::SideEffecting, // fulfillment/cancelled/refunded elevates to Tier 2 (OrderFulfillmentElevationRule)
        'woocommerce/product-delete'      => Tier::SideEffecting, // force=true elevates to Tier 2 (ForceDeleteElevationRule); restore stays Tier 1
    ];
}
