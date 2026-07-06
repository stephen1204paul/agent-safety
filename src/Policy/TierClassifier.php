<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Policy;

/**
 * Resolves a verb (plus its call args) to a Tier, applying arg-aware
 * elevation rules where a single verb spans blast radii (SPEC §2).
 */
final class TierClassifier
{
    /** Order statuses that fire fulfillment + customer emails => irreversible. */
    private const FULFILLMENT_STATUSES = ['processing', 'completed', 'shipped'];

    /**
     * @param array<string, mixed> $args
     * @return Tier|null Null when the verb is unknown (caller must fail closed).
     */
    public function classify(string $verb, array $args = []): ?Tier
    {
        $base = VerbCatalog::baseTier($verb);
        if ($base === null) {
            return null;
        }

        // orders-update flipping to a fulfillment status is irreversible.
        if ($verb === 'woocommerce/orders-update') {
            $status = is_string($args['status'] ?? null) ? strtolower($args['status']) : null;
            if ($status !== null && in_array($status, self::FULFILLMENT_STATUSES, true)) {
                return Tier::Irreversible;
            }
        }

        // Bulk delete elevates beyond single-item (single = recoverable from snapshot).
        if ($verb === 'woocommerce/products-delete' && self::isBulk($args)) {
            return Tier::Irreversible;
        }

        return $base;
    }

    /**
     * Fail-closed guard for self-reported annotations (D3): an ability that
     * claims read-only while OUR catalog classifies it as a write is a lie or
     * a misconfiguration — refuse to trust it.
     *
     * @param array<string, mixed> $args
     */
    public function isReadonlyButWrites(string $verb, bool $selfReportedReadonly, array $args = []): bool
    {
        if (!$selfReportedReadonly) {
            return false;
        }
        $tier = $this->classify($verb, $args);

        return $tier !== null && $tier->isWrite();
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
