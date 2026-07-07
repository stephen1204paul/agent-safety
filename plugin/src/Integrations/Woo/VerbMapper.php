<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

/**
 * Maps an MCP tool name to our canonical verb id (= the WP Ability id) for
 * WooCommerce-registered abilities.
 *
 * VERIFIED against Woo 10.8.1 + WP 7.0 (tools/list over the live MCP endpoint):
 * the tool name is "woocommerce-{resource}-{action}" and the ability id is
 * "woocommerce/{resource}-{action}" — i.e. the namespace separator is the FIRST
 * hyphen, which becomes a slash. Example: "woocommerce-orders-update" ->
 * "woocommerce/orders-update". The `agent_safety_map_verb` filter remains so
 * extensions registering their own Woo-namespaced abilities can extend the mapping.
 *
 * This mapper is WooCommerce-specific by construction (the hyphen->slash rule
 * only holds for Woo's own tool-naming convention) — it is wired up only when
 * {@see WooIntegration::available()}, never as a generic default.
 */
final class VerbMapper
{
    public function toVerb(string $toolName): string
    {
        $name = strtolower($toolName);
        // Replace only the first hyphen (the namespace boundary) with a slash.
        $verb = preg_replace('/^([a-z0-9]+)-/', '$1/', $name, 1) ?? $name;

        /** @var string $verb */
        $verb = apply_filters('agent_safety_map_verb', $verb, $toolName);

        return $verb;
    }
}
