<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Packs\PackRegistry;

/**
 * Resolves the calling credential to a Capability Pack.
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
        return $this->boundToken() !== null
            ? $this->registry()->resolve($this->boundToken())
            : $this->registry()->resolve(null);
    }

    /**
     * The request's PRINCIPAL: whichever token {@see resolve()} actually
     * bound the pack from, or the first current token when nothing is
     * bound, or null when there are no current tokens at all. Wired into
     * {@see RequestContext::configurePrincipalResolver()} at bootstrap so
     * the audit actor, rate limits, tripwires, argument caps, grant subject
     * and the approval's `key_id` all name the SAME credential the pack
     * decision was made on — never a different, earlier-in-chain token that
     * merely happened to authenticate first (e.g. WooCommerce's
     * `wp_set_current_user()` side effect ranking `user:<id>` ahead of the
     * bound `wc:<key_id>` in provider order). Deliberately does NOT change
     * which pack is resolved; it only names the token that earned it.
     */
    public function principal(): ?string
    {
        $bound = $this->boundToken();
        if ($bound !== null) {
            return $bound;
        }

        $tokens = RequestContext::currentTokens();

        return $tokens[0] ?? null;
    }

    /** The first current token with a stored binding, or null when none has one. */
    private function boundToken(): ?string
    {
        $bindings = $this->registry()->bindings();

        foreach (RequestContext::currentTokens() as $token) {
            if (isset($bindings[$token])) {
                return $token;
            }
        }

        return null;
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

        $registry = PackRegistry::withBuiltins($this->storedBindings());
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
     * Public because the admin page diffs a save against the STORED bindings —
     * {@see registry()} may have been replaced by the `agent_safety_pack_registry`
     * filter, so its bindings are not necessarily what the option holds.
     *
     * @return array<string, string>
     */
    public function storedBindings(): array
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
