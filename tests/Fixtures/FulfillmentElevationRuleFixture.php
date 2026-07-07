<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Fixtures;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Test-only mirror of the moved `Specflux\AgentSafety\Plugin\Integrations\Woo\OrderFulfillmentElevationRule`:
 * an order status flip to a fulfillment state fires customer emails +
 * fulfillment, which is irreversible regardless of the verb's base tier.
 */
final class FulfillmentElevationRuleFixture implements ElevationRule
{
    private const FULFILLMENT_STATUSES = ['processing', 'completed', 'shipped'];

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
