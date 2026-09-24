<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\Pack;

/**
 * Woo-flavoured Capability Packs, registered into the core
 * {@see \Specflux\AgentSafety\Packs\PackRegistry} by {@see WooIntegration::register()}
 * on top of the (now generic, fail-closed) core builtins:
 *   - woo-default-agent — catalog read/write (both the MCP-bridge plural
 *                         verbs and the session-visible singular
 *                         product/order verbs), but every Tier-2
 *                         (irreversible) verb is approval-gated. This is
 *                         what "default-agent" meant before the core
 *                         catalog went generic; kept as a Woo pack so sites
 *                         running WooCommerce still have a sensible
 *                         non-fail-closed option to bind credentials to.
 *   - support-agent     — the same read/write allow list with Tier-2
 *                         HARD-WALLED via `deny_class` — injection-proof
 *                         against irreversible abuse by construction: the
 *                         verb isn't reachable.
 *
 * Plus the roadmap-0.2 starter presets, so first-run configuration is a
 * CHOICE, not policy authoring:
 *   - readonly-analyst  — reads and queries only. Belt and braces: the allow
 *                         list names only read/query verbs AND every write
 *                         class is hard-denied, so a future read-looking verb
 *                         that writes still can't slip through.
 *   - fulfillment-bot   — order reads plus orders-update (including the
 *                         status transitions OrderFulfillmentElevationRule
 *                         elevates to Tier-2 — fulfilling is this bot's job,
 *                         so that elevation is allowed without approval).
 *                         Because nothing in this pack ever asks a human,
 *                         argument caps pin the update to what fulfilling
 *                         IS: `status` may only move between the
 *                         pending/on-hold/processing/completed/shipped
 *                         states (cancelling or marking refunded belongs to
 *                         a pack that gates Tier-2 on approval), and the
 *                         order's money and identity fields — set_paid,
 *                         customer_id, billing, shipping, the line/shipping/
 *                         fee/coupon lines, transaction_id — may not appear
 *                         in the call at all. Refunds and customer email are
 *                         unreachable BY CONSTRUCTION: they are simply not in
 *                         the allow list.
 */
final class WooPacks
{
    /** The only `status` values a fulfillment bot may move an order to. */
    private const FULFILLMENT_STATUSES = ['pending', 'on-hold', 'processing', 'completed', 'shipped'];

    /**
     * Order fields a fulfillment bot never has business writing: each one
     * either moves money or rewrites who the order belongs to.
     */
    private const FULFILLMENT_FORBIDDEN_KEYS = [
        'set_paid',
        'customer_id',
        'billing',
        'shipping',
        'line_items',
        'shipping_lines',
        'fee_lines',
        'coupon_lines',
        'transaction_id',
    ];

    /** @return list<Pack> */
    public static function all(): array
    {
        return [
            new Pack(
                name: 'woo-default-agent',
                allow: ['woocommerce/products-*', 'woocommerce/orders-*', 'woocommerce/product-*', 'woocommerce/order-*'],
                approvalByClass: ['tier2' => true],
            ),
            new Pack(
                name: 'support-agent',
                allow: ['woocommerce/products-*', 'woocommerce/orders-*', 'woocommerce/product-*', 'woocommerce/order-*'],
                denyClass: ['tier2'],
            ),
            new Pack(
                name: 'readonly-analyst',
                allow: [
                    'woocommerce/products-list',
                    'woocommerce/products-get',
                    'woocommerce/orders-list',
                    'woocommerce/orders-get',
                    'woocommerce/products-query',
                    'woocommerce/orders-query',
                ],
                denyClass: ['tier1', 'tier2'],
            ),
            new Pack(
                name: 'fulfillment-bot',
                allow: [
                    'woocommerce/orders-list',
                    'woocommerce/orders-get',
                    'woocommerce/orders-update',
                ],
                argumentCaps: [
                    new ArgumentCap(
                        id: 'order_status',
                        verbs: 'woocommerce/orders-update',
                        argPath: 'status',
                        allowedValues: self::FULFILLMENT_STATUSES,
                    ),
                    ...self::forbiddenOn('woocommerce/orders-update', self::FULFILLMENT_FORBIDDEN_KEYS),
                ],
            ),
        ];
    }

    /**
     * One forbidden-key cap per field, each named after the field so a denial
     * reads "argument_cap_billing_forbidden_argument" in the audit trail.
     *
     * @param list<string> $keys
     * @return list<ArgumentCap>
     */
    private static function forbiddenOn(string $verb, array $keys): array
    {
        return array_map(
            static fn (string $key): ArgumentCap => new ArgumentCap(id: $key, verbs: $verb, argPath: $key, forbidden: true),
            $keys,
        );
    }
}
