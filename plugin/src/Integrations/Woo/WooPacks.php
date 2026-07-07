<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Packs\Pack;

/**
 * Woo-flavoured Capability Packs (SPEC §3), registered into the core
 * {@see \Specflux\AgentSafety\Packs\PackRegistry} by {@see WooIntegration::register()}
 * on top of the (now generic, fail-closed) core builtins:
 *   - woo-default-agent — catalog read/write, but every Tier-2 (irreversible)
 *                         verb is approval-gated. This is what "default-agent"
 *                         meant before the core catalog went generic; kept as
 *                         a Woo pack so sites running WooCommerce still have a
 *                         sensible non-fail-closed option to bind credentials to.
 *   - support-agent     — catalog read/write with Tier-2 HARD-WALLED via
 *                         `deny_class` — injection-proof against refund/email
 *                         abuse by construction (D9): the verb isn't reachable.
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
        ];
    }
}
