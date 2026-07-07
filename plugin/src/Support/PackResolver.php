<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Packs\PackRegistry;

/**
 * Resolves the calling credential to a Capability Pack (SPEC §3 / D20).
 *
 * The principal is whichever {@see RequestContext::currentTokens()} candidate
 * (application password, user, role, or an integration's own identity — e.g.
 * a WooCommerce API key) is bound first. Admin-configured bindings (the
 * `agsafe_pack_bindings` option, token id => pack name) map each credential to
 * a pack from the catalog; an unbound/unmatched request gets the safe default
 * ({@see PackRegistry::DEFAULT_PACK}). The same resolved pack is shared by the
 * gate and the audit hook within a request, so both see one consistent scope.
 *
 * The registry is memoized per request (resolution runs once per ability
 * registration and again per audit write).
 */
final class PackResolver
{
    public const BINDINGS_OPTION = 'agsafe_pack_bindings';

    private ?PackRegistry $registry = null;

    /**
     * @param list<Pack> $extraPacks Packs contributed by integrations (e.g. the
     *                                WooCommerce module) on top of the core builtins.
     */
    public function __construct(private readonly array $extraPacks = [])
    {
    }

    /**
     * Resolve THIS request's pack: the first current candidate token
     * ({@see RequestContext::currentTokens()}) with a stored binding wins,
     * highest-priority provider first; otherwise the registry default.
     */
    public function resolve(): Pack
    {
        $registry = $this->registry();
        $bindings = $registry->bindings();

        foreach (RequestContext::currentTokens() as $token) {
            if (isset($bindings[$token])) {
                return $registry->resolve($token);
            }
        }

        return $registry->resolve(null);
    }

    /**
     * The registry over the built-in + integration-contributed catalog and the
     * persisted bindings. Exposed so the admin Packs UI can list the catalog
     * and current bindings. Filterable via `agent_safety_pack_registry`
     * for bespoke catalogs/bindings.
     */
    public function registry(): PackRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $registry = PackRegistry::withBuiltins($this->loadBindings());
        foreach ($this->extraPacks as $pack) {
            $registry->register($pack);
        }

        /** @var PackRegistry $registry */
        $registry = function_exists('apply_filters')
            ? apply_filters('agent_safety_pack_registry', $registry)
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
     * (subject token id => pack name). Anything malformed is dropped, never trusted.
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
