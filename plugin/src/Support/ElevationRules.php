<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Policy\ElevationRule;

/**
 * The host-side seam for contributing arg-aware tier-elevation rules.
 *
 * Until now the only way a verb's tier could depend on its ARGUMENTS was to
 * ship an {@see ElevationRule} inside an integration module — they are
 * constructor-injected into {@see \Specflux\AgentSafety\Policy\TierClassifier}
 * and nothing else could reach them. That left a real gap next to the two
 * seams that already exist: `agent_safety_governed_namespaces` lets a site
 * govern its own namespace and `agent_safety_verb_map` lets it map those verbs
 * to a BASE tier — but a verb whose blast radius depends on its args (publish
 * vs draft, a bulk delete vs a single one, exactly the shape the bundled rules
 * exist for) had no seam at all, so such a site had to over-classify the verb
 * as irreversible and gate every harmless call on a human.
 *
 * This closes it with the same posture as the other two filters:
 *
 *  - the filter may only ADD rules; the modules' own rules are passed in and
 *    a filter that drops them is not honoured (they are re-appended),
 *  - anything that is not an {@see ElevationRule} instance is dropped rather
 *    than trusted, so a malformed contribution degrades to "no extra rule"
 *    instead of fataling inside the classifier on the first governed call,
 *  - and a rule can only ever ELEVATE anyway — {@see \Specflux\AgentSafety\Policy\TierClassifier}
 *    never lets one lower a tier — so this seam cannot widen what a credential
 *    may do, only narrow it. That is what makes it safe to expose at all.
 */
final class ElevationRules
{
    public const FILTER = 'agent_safety_elevation_rules';

    /**
     * The rules the classifier should run: the modules' own, plus whatever the
     * filter contributed, in that order.
     *
     * @param list<ElevationRule> $moduleRules Rules contributed by integration modules.
     * @return list<ElevationRule>
     */
    public static function filtered(array $moduleRules): array
    {
        if (!function_exists('apply_filters')) {
            return $moduleRules;
        }

        /** @var mixed $filtered */
        $filtered = apply_filters(self::FILTER, $moduleRules);
        if (!is_array($filtered)) {
            return $moduleRules;
        }

        $extra = [];
        foreach ($filtered as $rule) {
            // Identity comparison, not in_array's loose default: two distinct
            // rule objects of the same class are two rules, and only the exact
            // instances already contributed are skipped as duplicates.
            if ($rule instanceof ElevationRule && !self::contains($moduleRules, $rule)) {
                $extra[] = $rule;
            }
        }

        return [...$moduleRules, ...$extra];
    }

    /**
     * @param list<ElevationRule> $rules
     */
    private static function contains(array $rules, ElevationRule $needle): bool
    {
        foreach ($rules as $rule) {
            if ($rule === $needle) {
                return true;
            }
        }

        return false;
    }
}
