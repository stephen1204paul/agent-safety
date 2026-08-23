<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Core;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Publishing (or scheduling) content elevates beyond draft-level edits:
 * publication pushes content into the public site immediately, is picked up
 * by feeds/caches/notifications the moment it lands, and cannot be un-seen.
 *
 * ARGUMENT CONTRACT — ASSUMED: the proposed `core/manage-content` ability has
 * no shipped argument schema yet; we read WP's ubiquitous `post_status`
 * vocabulary off an assumed `status` key. Draft/inherit-style statuses stay
 * at the base tier.
 */
final class PublishElevationRule implements ElevationRule
{
    private const ELEVATED_STATUSES = ['publish', 'future'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== CoreVerbCatalog::MANAGE_CONTENT) {
            return null;
        }

        $status = $args['status'] ?? null;

        return is_string($status) && in_array($status, self::ELEVATED_STATUSES, true)
            ? Tier::Irreversible
            : null;
    }
}
