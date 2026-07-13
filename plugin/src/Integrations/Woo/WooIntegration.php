<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use wpdb;

/**
 * The one place all WooCommerce coupling lives. Wired from the plugin
 * bootstrap ONLY when {@see available()} — a site without WooCommerce never
 * sees a `woocommerce/*` verb, pack, or identity provider, and the governed
 * ability namespace list stays empty (the permission/audit hooks become inert
 * no-ops).
 *
 * {@see register()} MUTATES the two registries that already exist and can
 * grow incrementally ({@see VerbCatalog::register()}, {@see IdentityChain::register()}).
 * Tier-elevation rules and capability packs are instead RETURNED, because
 * their consumers — {@see \Specflux\AgentSafety\Policy\TierClassifier} and
 * {@see \Specflux\AgentSafety\Plugin\Support\PackResolver} — take constructor-only
 * dependency injection and are built by bootstrap AFTER this call returns; there
 * is no pre-existing registry object of that shape to mutate yet.
 */
final class WooIntegration
{
    public static function available(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * @return array{elevationRules: list<ElevationRule>, packs: list<Pack>, governedNamespaces: list<string>}
     */
    public static function register(VerbCatalog $catalog, IdentityChain $identity, ?wpdb $db): array
    {
        $catalog->register(WooVerbCatalog::MAP);

        // The API-key identity needs $wpdb for its lookups; on the rare request
        // where it's unavailable, everything else Woo still registers.
        if ($db !== null) {
            $identity->register(new WcApiKeyIdentity($db));
        }

        return [
            'elevationRules' => [
                new OrderFulfillmentElevationRule(),
                new BulkProductDeleteElevationRule(),
            ],
            'packs' => WooPacks::all(),
            'governedNamespaces' => ['woocommerce/'],
        ];
    }
}
