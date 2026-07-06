<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Plugin\Support;

use Specflux\WooAgentSafety\Packs\Pack;
use Specflux\WooAgentSafety\Packs\PackRegistry;

/**
 * Resolves the calling credential to a Capability Pack (SPEC §3 / D20).
 *
 * The principal is the authenticated WooCommerce API key behind the MCP request
 * ({@see RequestContext::tokenId()}, e.g. "key_7"). Admin-configured bindings
 * (the `was_pack_bindings` option, key id => pack name) map each credential to a
 * pack from the built-in catalog; an unbound key gets the safe default
 * ({@see PackRegistry::DEFAULT_PACK}). The same resolved pack is shared by the
 * gate and the audit hook within a request, so both see one consistent scope.
 *
 * The registry is memoized per request (resolution runs once per ability
 * registration and again per audit write).
 */
final class PackResolver
{
    public const BINDINGS_OPTION = 'was_pack_bindings';

    private ?PackRegistry $registry = null;

    public function resolve(): Pack
    {
        return $this->registry()->resolve(RequestContext::tokenId());
    }

    /**
     * The registry over the built-in catalog and the persisted bindings. Exposed
     * so the admin Packs UI can list the catalog and current bindings. Filterable
     * via `woo_agent_safety_pack_registry` for bespoke catalogs/bindings.
     */
    public function registry(): PackRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $registry = PackRegistry::withBuiltins($this->loadBindings());

        /** @var PackRegistry $registry */
        $registry = function_exists('apply_filters')
            ? apply_filters('woo_agent_safety_pack_registry', $registry)
            : $registry;

        return $this->registry = $registry;
    }

    /** Forget the memoized registry so the next resolve() re-reads the option. */
    public function flush(): void
    {
        $this->registry = null;
    }

    /**
     * Read + sanitise the bindings option to a clean array<string,string>
     * (subject key id => pack name). Anything malformed is dropped, never trusted.
     *
     * @return array<string, string>
     */
    private function loadBindings(): array
    {
        $raw = function_exists('get_option') ? get_option(self::BINDINGS_OPTION, []) : [];
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $subject => $pack) {
            if (is_string($subject) && is_string($pack) && $subject !== '' && $pack !== '') {
                $clean[$subject] = $pack;
            }
        }

        return $clean;
    }
}
