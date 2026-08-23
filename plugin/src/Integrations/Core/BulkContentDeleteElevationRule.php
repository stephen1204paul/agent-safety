<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Core;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Bulk content delete/trash elevates beyond a single-item delete: one item in
 * the trash is recoverable from the trash/snapshot, a mass purge is not.
 *
 * ARGUMENT CONTRACT — ASSUMED: the proposed `core/manage-content` ability has
 * no shipped argument schema yet, so `action` / `bulk` / `ids` here are our
 * assumed shapes. The bulk threshold (> {@see BULK_THRESHOLD} ids, or an
 * explicit `bulk` flag) deliberately mirrors the Woo
 * BulkProductDeleteElevationRule's spirit while using core's larger default,
 * per owner decision (bulk-delete threshold = 5).
 */
final class BulkContentDeleteElevationRule implements ElevationRule
{
    private const BULK_THRESHOLD = 5;

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== CoreVerbCatalog::MANAGE_CONTENT) {
            return null;
        }

        $action = $args['action'] ?? null;
        if (!is_string($action) || !in_array($action, ['delete', 'trash'], true)) {
            return null;
        }

        return self::isBulk($args) ? Tier::Irreversible : null;
    }

    /**
     * An explicit `bulk` flag is bulk regardless of count; otherwise only a
     * list of ids OVER the threshold is. Exactly-threshold batches stay at
     * the base tier (the threshold is exclusive).
     *
     * @param array<string, mixed> $args
     */
    private static function isBulk(array $args): bool
    {
        if (!empty($args['bulk'])) {
            return true;
        }
        $ids = $args['ids'] ?? null;

        return is_array($ids) && count($ids) > self::BULK_THRESHOLD;
    }
}
