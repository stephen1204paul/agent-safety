<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * The catalog of Capability Packs plus the credential→pack bindings (SPEC §3 / D20).
 *
 * Pure core: holds named {@see Pack} definitions and a map of subject (a
 * namespaced credential/role token id, e.g. "wc:key_7" or "role:editor") →
 * pack name, and resolves a request's subject to exactly one pack. The host
 * {@see \Specflux\AgentSafety\Plugin\Support\PackResolver} builds one of these
 * from persisted bindings; this class stays WP-free so the resolution rule is
 * unit-testable. Integrations contribute additional packs via {@see register()}.
 *
 * Resolution is fail-safe: an unbound or unknown subject falls back to the
 * default pack, and a binding that names a missing pack also falls back — a
 * dangling binding can never widen access beyond the default.
 */
final class PackRegistry
{
    public const DEFAULT_PACK = 'default-agent';

    /** @var array<string, Pack> name => pack */
    private array $packs;

    /** @var array<string, string> subject (key id) => pack name */
    private array $bindings;

    private string $default;

    /**
     * @param array<string, Pack>  $packs    name => Pack
     * @param array<string, string> $bindings subject (key id, e.g. "key_7") => pack name
     */
    public function __construct(array $packs, array $bindings = [], string $default = self::DEFAULT_PACK)
    {
        $this->packs = $packs;
        $this->bindings = $bindings;
        $this->default = $default;
    }

    /**
     * Build a registry over the built-in pack catalog with the given bindings.
     *
     * @param array<string, string> $bindings subject (key id) => pack name
     */
    public static function withBuiltins(array $bindings = [], string $default = self::DEFAULT_PACK): self
    {
        return new self(self::builtins(), $bindings, $default);
    }

    /**
     * The framework-agnostic pack catalog. Two reference scopes:
     *   - owner         — unrestricted (`allow: ["*"]`, no approval); the same
     *                     machinery with nothing walled off (SPEC §3).
     *   - default-agent — GENERIC FAIL-CLOSED (`allow: []`): the safe default
     *                     for an unbound credential on a host with no verbs
     *                     registered yet. Integrations widen this per-site by
     *                     registering their own default-flavoured pack (see
     *                     e.g. the WooCommerce integration's "woo-default-agent")
     *                     and binding credentials to it explicitly.
     *
     * @return array<string, Pack>
     */
    public static function builtins(): array
    {
        return [
            'owner' => new Pack(
                name: 'owner',
                allow: ['*'],
                pii: 'full',
            ),
            'default-agent' => new Pack(
                name: 'default-agent',
                allow: [],
            ),
        ];
    }

    /**
     * Add (or overwrite by name) a pack contributed by an integration on top of
     * the built-in catalog — e.g. a WooCommerce module registering its own
     * capability packs. Overwriting is intentional: a later registration for
     * the same name replaces the earlier one, same as {@see \Specflux\AgentSafety\Policy\VerbCatalog::register()}.
     */
    public function register(Pack $pack): void
    {
        $this->packs[$pack->name] = $pack;
    }

    /**
     * Resolve a request's subject (WC key id, or null for non-API-key callers) to
     * its pack. Unbound, unknown, or dangling-binding subjects get the default.
     */
    public function resolve(?string $subject): Pack
    {
        $name = ($subject !== null && isset($this->bindings[$subject]))
            ? $this->bindings[$subject]
            : $this->default;

        return $this->packs[$name]
            ?? $this->packs[$this->default]
            ?? new Pack(name: $this->default, allow: []);
    }

    public function get(string $name): ?Pack
    {
        return $this->packs[$name] ?? null;
    }

    /** @return list<string> pack names in catalog order */
    public function names(): array
    {
        return array_keys($this->packs);
    }

    /** @return array<string, string> subject (key id) => pack name */
    public function bindings(): array
    {
        return $this->bindings;
    }

    public function defaultPack(): string
    {
        return $this->default;
    }
}
