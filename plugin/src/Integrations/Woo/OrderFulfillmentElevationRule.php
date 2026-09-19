<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * An order status update flipping to a status with customer-facing side
 * effects is irreversible regardless of the verb's base tier: fulfillment
 * statuses fire fulfillment + customer emails, `cancelled` restocks and
 * emails the customer, `refunded` marks the money as returned.
 */
final class OrderFulfillmentElevationRule implements ElevationRule
{
    /** Order statuses whose transition cannot be quietly undone => irreversible. */
    private const IRREVERSIBLE_STATUSES = ['processing', 'completed', 'shipped', 'cancelled', 'refunded'];

    /** The MCP-bridge verb and the session-visible verb both carry a `status` arg. */
    private const VERBS = ['woocommerce/orders-update', 'woocommerce/order-update-status'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if (!in_array($verb, self::VERBS, true)) {
            return null;
        }

        $status = is_string($args['status'] ?? null) ? strtolower($args['status']) : null;

        return $status !== null && in_array($status, self::IRREVERSIBLE_STATUSES, true)
            ? Tier::Irreversible
            : null;
    }
}
