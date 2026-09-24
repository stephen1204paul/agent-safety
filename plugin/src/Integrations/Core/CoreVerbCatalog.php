<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Core;

use Specflux\AgentSafety\Policy\Tier;

/**
 * OUR tier assignments for WordPress core verbs, contributed into the core
 * {@see \Specflux\AgentSafety\Policy\VerbCatalog} by {@see CoreIntegration::register()}.
 * Authoritative like {@see \Specflux\AgentSafety\Plugin\Integrations\Woo\WooVerbCatalog}:
 * if a core ability self-reports read-only but appears here as a write, the
 * gate fails closed.
 *
 * Unlike Woo there are NO wildcard entries here on purpose: `core/` is
 * governed by namespace (D23) while most verbs it will govern don't exist yet,
 * and a glob like `core/manage-*` would pre-classify verbs we have never seen,
 * defeating the fail-closed default for unknown ones. Any `core/*` ability not
 * listed in {@see MAP} is refused as `unknown_verb` — including a future core
 * write ability that has not yet been mapped here.
 */
final class CoreVerbCatalog
{
    // Verb ids are the canonical WP Ability ids registered by WordPress core.
    // These are the only three abilities WordPress core registers today,
    // verified against WordPress core wp-includes/abilities.php (7.0.0 and
    // trunk).
    public const GET_SITE_INFO = 'core/get-site-info';
    public const GET_ENVIRONMENT_INFO = 'core/get-environment-info';
    public const GET_USER_INFO = 'core/get-user-info';

    /** @var array<string, Tier> */
    public const MAP = [
        // The 3 abilities WordPress core exposes today (all read-only).
        self::GET_SITE_INFO       => Tier::Reversible,
        self::GET_ENVIRONMENT_INFO => Tier::Reversible,
        self::GET_USER_INFO       => Tier::Reversible,
    ];
}
