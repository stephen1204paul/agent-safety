<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * The catalog of Capability Packs plus the credential→pack bindings (SPEC §3 / D20).
 *
 * Pure core: holds named {@see Pack} definitions and a map of subject (the
 * WooCommerce API key id, e.g. "key_7") → pack name, and resolves a request's
 * subject to exactly one pack. The host {@see \Specflux\AgentSafety\Plugin\Support\PackResolver}
 * builds one of these from persisted bindings; this class stays WP-free so the
 * resolution rule is unit-testable.
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
     * The shipped pack catalog. Three reference scopes spanning the policy space:
     *   - owner         — unrestricted (`allow: ["*"]`, no approval); the same
     *                     machinery with nothing walled off (SPEC §3).
     *   - default-agent — catalog read/write, but every Tier-2 (irreversible)
     *                     verb is approval-gated. The safe default for an
     *                     unbound credential.
     *   - support-agent — catalog read/write with Tier-2 HARD-WALLED via
     *                     `deny_class` — injection-proof against refund/email
     *                     abuse by construction (D9): the verb isn't reachable.
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
                allow: ['woocommerce/products-*', 'woocommerce/orders-*'],
                approvalByClass: ['tier2' => true],
            ),
            'support-agent' => new Pack(
                name: 'support-agent',
                allow: ['woocommerce/products-*', 'woocommerce/orders-*'],
                denyClass: ['tier2'],
            ),
        ];
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
