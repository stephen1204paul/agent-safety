<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Fixtures;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Test-only mirror of the moved `Specflux\AgentSafety\Plugin\Integrations\Woo\BulkProductDeleteElevationRule`:
 * a bulk delete elevates beyond single-item (single = recoverable from snapshot).
 */
final class BulkDeleteElevationRuleFixture implements ElevationRule
{
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== 'woocommerce/products-delete' || !self::isBulk($args)) {
            return null;
        }

        return Tier::Irreversible;
    }

    /** @param array<string, mixed> $args */
    private static function isBulk(array $args): bool
    {
        if (!empty($args['bulk'])) {
            return true;
        }
        $ids = $args['ids'] ?? null;

        return is_array($ids) && count($ids) > 1;
    }
}
