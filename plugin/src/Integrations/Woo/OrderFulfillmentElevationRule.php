<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * An order status update flipping to a fulfillment status fires fulfillment +
 * customer emails, which is irreversible regardless of the verb's
 * base tier.
 */
final class OrderFulfillmentElevationRule implements ElevationRule
{
    /** Order statuses that fire fulfillment + customer emails => irreversible. */
    private const FULFILLMENT_STATUSES = ['processing', 'completed', 'shipped'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'woocommerce/orders-update') {
            return null;
        }

        $status = is_string($args['status'] ?? null) ? strtolower($args['status']) : null;

        return $status !== null && in_array($status, self::FULFILLMENT_STATUSES, true)
            ? Tier::Irreversible
            : null;
    }
}
