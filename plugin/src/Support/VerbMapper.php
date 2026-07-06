<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Maps an MCP tool name to our canonical verb id (= the WP Ability id).
 *
 * VERIFIED against Woo 10.8.1 + WP 7.0 (tools/list over the live MCP endpoint):
 * the tool name is "woocommerce-{resource}-{action}" and the ability id is
 * "woocommerce/{resource}-{action}" — i.e. the namespace separator is the FIRST
 * hyphen, which becomes a slash. Example: "woocommerce-orders-update" ->
 * "woocommerce/orders-update". The `woo_agent_safety_map_verb` filter remains so
 * extensions registering their own abilities can extend the mapping.
 */
final class VerbMapper
{
    public function toVerb(string $toolName): string
    {
        $name = strtolower($toolName);
        // Replace only the first hyphen (the namespace boundary) with a slash.
        $verb = preg_replace('/^([a-z0-9]+)-/', '$1/', $name, 1) ?? $name;

        /** @var string $verb */
        $verb = apply_filters('woo_agent_safety_map_verb', $verb, $toolName);

        return $verb;
    }
}
