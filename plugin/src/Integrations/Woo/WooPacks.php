<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\Pack;

/**
 * Woo-flavoured Capability Packs, registered into the core
 * {@see \Specflux\AgentSafety\Packs\PackRegistry} by {@see WooIntegration::register()}
 * on top of the (now generic, fail-closed) core builtins:
 *   - woo-default-agent — catalog read/write, but every Tier-2 (irreversible)
 *                         verb is approval-gated. This is what "default-agent"
 *                         meant before the core catalog went generic; kept as
 *                         a Woo pack so sites running WooCommerce still have a
 *                         sensible non-fail-closed option to bind credentials to.
 *   - support-agent     — catalog read/write with Tier-2 HARD-WALLED via
 *                         `deny_class` — injection-proof against refund/email
 *                         abuse by construction: the verb isn't reachable.
 *
 * Plus the roadmap-0.2 starter presets, so first-run configuration is a
 * CHOICE, not policy authoring:
 *   - readonly-analyst  — reads and reports only. Belt and braces: the allow
 *                         list names only read verbs AND every write class is
 *                         hard-denied, so a future read-looking verb that
 *                         writes still can't slip through.
 *   - fulfillment-bot   — order reads plus orders-update (including the
 *                         status transitions OrderFulfillmentElevationRule
 *                         elevates to Tier-2 — fulfilling is this bot's job,
 *                         so that elevation is allowed without approval).
 *                         Refunds and customer email are unreachable BY
 *                         CONSTRUCTION: they are simply not in the allow list.
 *   - refund-desk       — reads plus orders-refund. Every refund is
 *                         approval-gated (Tier-2), and the roadmap-0.2 spend
 *                         limits bound the blast radius even of approved
 *                         work: 500 per refund, 2000 refunded per UTC day.
 *                         The amounts are deliberate conservative defaults in
 *                         store currency units — clone the pack via the
 *                         `agent_safety_pack_registry` filter to retune them.
 */
final class WooPacks
{
    /** @return list<Pack> */
    public static function all(): array
    {
        return [
            new Pack(
                name: 'woo-default-agent',
                allow: ['woocommerce/products-*', 'woocommerce/orders-*'],
                approvalByClass: ['tier2' => true],
            ),
            new Pack(
                name: 'support-agent',
                allow: ['woocommerce/products-*', 'woocommerce/orders-*'],
                denyClass: ['tier2'],
            ),
            new Pack(
                name: 'readonly-analyst',
                allow: [
                    'woocommerce/products-list',
                    'woocommerce/products-get',
                    'woocommerce/orders-list',
                    'woocommerce/orders-get',
                    'woocommerce/reports-*',
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
            ),
            new Pack(
                name: 'refund-desk',
                allow: [
                    'woocommerce/products-list',
                    'woocommerce/products-get',
                    'woocommerce/orders-list',
                    'woocommerce/orders-get',
                    'woocommerce/orders-refund',
                ],
                approvalByClass: ['tier2' => true],
                argumentCaps: [
                    new ArgumentCap(
                        id: 'refund_amount',
                        verbs: 'woocommerce/orders-refund',
                        argPath: 'amount',
                        maxPerCall: 500.0,
                        maxTotalPerDay: 2000.0,
                    ),
                ],
            ),
        ];
    }
}
