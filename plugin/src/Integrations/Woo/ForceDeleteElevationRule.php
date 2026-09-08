<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * A product delete that carries the Woo REST `force` flag skips the trash
 * and deletes permanently, which no snapshot restores. Only a CLEARLY false
 * flag keeps the base tier: absent, false, 0, '0', '', or 'false' in any
 * case. Every other shape — true, 1, '1', 'true', 'yes', an array — elevates,
 * because guessing wrong in the other direction is unrecoverable.
 */
final class ForceDeleteElevationRule implements ElevationRule
{
    private const VERBS = ['woocommerce/products-delete'];

    private const CLEARLY_FALSE = ['', '0', 'false'];

    /** @param array<string, mixed> $args */
    public function apply(string $verb, array $args, Tier $currentTier): ?Tier
    {
        if (!in_array($verb, self::VERBS, true) || !array_key_exists('force', $args)) {
            return null;
        }

        return self::isClearlyFalse($args['force']) ? null : Tier::Irreversible;
    }

    private static function isClearlyFalse(mixed $force): bool
    {
        if ($force === false || $force === 0) {
            return true;
        }

        return is_string($force) && in_array(strtolower($force), self::CLEARLY_FALSE, true);
    }
}
