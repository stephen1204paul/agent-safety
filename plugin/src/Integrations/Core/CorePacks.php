<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Core;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Core-flavoured Capability Packs, registered into the core
 * {@see \Specflux\AgentSafety\Packs\PackRegistry} by {@see CoreIntegration::register()}
 * on top of the builtins. Same shape and safety posture as
 * {@see \Specflux\AgentSafety\Plugin\Integrations\Woo\WooPacks}, trimmed to the
 * one preset the three live core verbs support:
 *
 *   - site-readonly — the three reads only, AND every write class hard-walled
 *                     via denyClass: belt and braces, so a read-looking verb
 *                     that writes still can't slip through.
 *
 * Public claim (§3.1 item 6, used verbatim in the readme and here): "Governs
 * the three abilities WordPress core registers today. Any other `core/*`
 * ability is denied until Agent Safety maps it, so future core write
 * abilities fail closed rather than run ungoverned."
 */
final class CorePacks
{
    /** @return list<Pack> */
    public static function all(): array
    {
        $readVerbs = [
            CoreVerbCatalog::GET_SITE_INFO,
            CoreVerbCatalog::GET_ENVIRONMENT_INFO,
            CoreVerbCatalog::GET_USER_INFO,
        ];

        return [
            new Pack(
                name: 'site-readonly',
                allow: $readVerbs,
                denyClass: [Tier::SideEffecting->classSlug(), Tier::Irreversible->classSlug()],
            ),
        ];
    }
}
