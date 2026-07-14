<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * The one glob dialect used everywhere a pack config names verbs: "*" alone
 * matches anything; in any other pattern "*" is a wildcard segment and the
 * rest is literal. Shared by {@see Pack::allows()} and
 * {@see ArgumentCap::appliesTo()} so a cap's verb scope can never drift from
 * the allow-list's matching rules.
 */
final class VerbGlob
{
    public static function matches(string $pattern, string $subject): bool
    {
        if ($pattern === '*') {
            return true;
        }
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';

        return (bool) preg_match($regex, $subject);
    }
}
