<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Plugin\Approval\StateProbe;

/**
 * The host-side seam for contributing state probes (AS-6).
 *
 * Until now the only way a verb got a {@see StateProbe} was to ship one
 * inside an integration module (WooCommerce's product/order probes) — there
 * was no way for a site to add one of its own for a verb it governs through
 * `agent_safety_governed_namespaces`/`agent_safety_verb_map` without shipping
 * its own integration module. This closes that gap with the SAME posture as
 * {@see ElevationRules}:
 *
 *  - the filter may only ADD probes, keyed by verb; a module's own probe for
 *    a verb can never be overridden or removed by the filter — a site cannot
 *    weaken staleness detection a module already wired up, only add it where
 *    none existed,
 *  - anything that is not a {@see StateProbe} instance, or whose verb key
 *    already belongs to a module, is dropped rather than trusted, so a
 *    malformed or conflicting contribution degrades to "no probe for that
 *    verb" (unfingerprinted, the pre-AS-6 behaviour) instead of silently
 *    shadowing a module's probe or fataling inside the pipeline,
 *  - and a probe can only ever ADD a staleness check on top of whatever tier
 *    gating already applies — it never grants or widens what a credential
 *    may do. That is what makes it safe to expose at all.
 */
final class StateProbes
{
    public const FILTER = 'agent_safety_state_probes';

    /**
     * @param array<string, StateProbe> $moduleProbes Verb => probe, contributed by integration modules.
     * @return array<string, StateProbe>
     */
    public static function filtered(array $moduleProbes): array
    {
        if (!function_exists('apply_filters')) {
            return $moduleProbes;
        }

        /** @var mixed $filtered */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is the literal, prefixed 'agent_safety_state_probes' constant; PHPCS can't resolve a class constant statically.
        $filtered = apply_filters(self::FILTER, $moduleProbes);
        if (!is_array($filtered)) {
            return $moduleProbes;
        }

        $result = $moduleProbes;
        foreach ($filtered as $verb => $probe) {
            if (!is_string($verb) || isset($moduleProbes[$verb]) || !($probe instanceof StateProbe)) {
                continue;
            }
            $result[$verb] = $probe;
        }

        return $result;
    }
}
