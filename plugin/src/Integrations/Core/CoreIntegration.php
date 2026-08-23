<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Core;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use wpdb;

/**
 * The one place all WordPress-core coupling lives. Wired from the plugin
 * bootstrap UNCONDITIONALLY — unlike {@see \Specflux\AgentSafety\Plugin\Integrations\Woo\WooIntegration},
 * core is definitionally present on every site this plugin can run on.
 *
 * The module registers no identity provider of its own: identity for
 * core-namespace calls comes from the bootstrap's always-on chain
 * (application passwords + user/role). {@see register()} mutates only the two
 * registries that already exist ({@see VerbCatalog::register()}) and RETURNS
 * elevation rules / packs / governed namespaces for their constructor-only
 * consumers, exactly like the Woo module — see that class's docblock for why.
 *
 * D23: `core/` is governed BY NAMESPACE and fails closed on verbs missing
 * from the catalog — most verbs this namespace will govern don't exist yet,
 * so unknown_verb denial IS the correct posture until they land and get
 * mapped here.
 */
final class CoreIntegration
{
    public static function available(): bool
    {
        return true;
    }

    /**
     * @return array{elevationRules: list<ElevationRule>, packs: list<Pack>, governedNamespaces: list<string>}
     */
    public static function register(VerbCatalog $catalog, IdentityChain $identity, ?wpdb $db): array
    {
        // Identity ($identity) and $db are unused by design (no provider below);
        // the signature is kept identical to WooIntegration so the bootstrap can
        // treat modules uniformly.
        $catalog->register(CoreVerbCatalog::MAP);

        return [
            'elevationRules' => [
                new PublishElevationRule(),
                new BulkContentDeleteElevationRule(),
                new UserRoleChangeElevationRule(),
            ],
            'packs' => CorePacks::all(),
            'governedNamespaces' => ['core/'],
        ];
    }
}
