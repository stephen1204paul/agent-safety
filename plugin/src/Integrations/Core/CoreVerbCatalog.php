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
 * defeating the fail-closed default for unknown ones.
 */
final class CoreVerbCatalog
{
    // Verb ids are the canonical WP Ability ids registered by WordPress core.
    // The three LIVE ones ship in the Abilities API today (verified against
    // abilities-api trunk: wp-core-abilities.php registers exactly these).
    public const GET_SITE_INFO = 'core/get-site-info';
    public const GET_ENVIRONMENT_INFO = 'core/get-environment-info';
    public const GET_USER_INFO = 'core/get-user-info';

    /**
     * Proposed names from the July 2026 merge proposal. Referenced through
     * these constants (never string literals) so an upstream rename is a
     * one-place change HERE.
     */
    public const READ_SETTINGS = 'core/read-settings';
    public const READ_CONTENT = 'core/read-content';
    public const READ_USERS = 'core/read-users';
    public const MANAGE_CONTENT = 'core/manage-content';
    public const MANAGE_SETTINGS = 'core/manage-settings';
    public const MANAGE_USERS = 'core/manage-users';

    /**
     * Verbs proposed by the merge proposal but not yet in core as of WP 7.1.
     * Kept in a single named sub-array merged into {@see MAP} so the
     * forward-compat intent is auditable at a glance.
     *
     * @see https://make.wordpress.org/core/2026/07/02/merge-proposal-expanding-wordpress-core-abilities/
     */
    public const PROPOSED = [
        self::READ_SETTINGS   => Tier::Reversible,
        self::READ_CONTENT    => Tier::Reversible,
        self::READ_USERS      => Tier::Reversible, // PII: see ToolCallResultRedactor's user-info masking (D26).
        self::MANAGE_CONTENT  => Tier::SideEffecting, // publish/bulk-delete elevate to Tier 2 (D25 rules).
        self::MANAGE_SETTINGS => Tier::Irreversible,
        self::MANAGE_USERS    => Tier::Irreversible, // role changes elevate to Tier 2 (UserRoleChangeElevationRule).
    ];

    /** @var array<string, Tier> */
    public const MAP = [
        // The 3 abilities WordPress core exposes today (all read-only).
        self::GET_SITE_INFO       => Tier::Reversible,
        self::GET_ENVIRONMENT_INFO => Tier::Reversible,
        self::GET_USER_INFO       => Tier::Reversible,

        // Forward-compat placeholders from the merge proposal; NOT in core as
        // of WP 7.1 — rename here when they land (see PROPOSED above).
        ...self::PROPOSED,
    ];
}
