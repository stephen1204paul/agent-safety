<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Core;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Changing what a user CAN DO (role(s) or direct capabilities) elevates a
 * user-management call to irreversible: privilege grants cascade into every
 * future action that user takes and cannot be undone by reverting a field —
 * whatever the agent's target did while elevated stays done.
 *
 * ARGUMENT CONTRACT — ASSUMED: the proposed `core/manage-users` ability has
 * no shipped argument schema yet. We treat the PRESENCE of any of the
 * `role`, `roles`, or `capabilities` keys as a privilege change (matching
 * WP_User edit semantics), whatever their values. If core lands different
 * keys, the rule silently never fires and the verb keeps its base Tier-2 —
 * still irreversible, still approval-gated by every pack that gates tier2.
 */
final class UserRoleChangeElevationRule implements ElevationRule
{
    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if ($verb !== CoreVerbCatalog::MANAGE_USERS) {
            return null;
        }

        foreach (['role', 'roles', 'capabilities'] as $key) {
            if (array_key_exists($key, $args)) {
                return Tier::Irreversible;
            }
        }

        return null;
    }
}
