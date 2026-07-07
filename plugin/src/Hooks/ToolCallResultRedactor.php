<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Hooks;

use Specflux\AgentSafety\Audit\Redactor;
use Specflux\AgentSafety\Plugin\Support\PackResolver;

/**
 * Read-path PII redaction (backlog #11) — closes the gap {@see Redactor}'s own
 * docblock names as "still-pending work": that class only masks what gets
 * WRITTEN TO THE AUDIT LOG; the payload RETURNED TO THE AGENT was, until this
 * hook, never touched. This hook masks the RESPONSE the agent actually
 * receives, for calls whose resolved pack demands it.
 *
 * Hooks `mcp_adapter_tool_call_result` (code-confirmed against upstream
 * `ToolsHandler::call_tool()`, signature `($result, $args, $tool_name,
 * $mcp_tool, $mcp)`), which fires ONLY on the EXECUTED path — after
 * `$mcp_tool->execute($args)` returns, before the result is shaped into a
 * response DTO. It never fires for denied/blocked calls (those return before
 * execution) — nothing to redact there, since nothing executed. Forward-compat
 * only: dormant, like {@see PreToolCallGate}, on Woo 10.8.1's vendored adapter
 * 0.1.0, which predates this filter (introduced 0.5.0).
 *
 * Scope: array-shaped results only. A `WP_Error` is passed through UNTOUCHED —
 * mutating it would risk losing the error message on the one path that must
 * stay human-readable, and a `WP_Error` is a control-flow signal, not a data
 * payload, so it carries no PII shape `Redactor` understands anyway. Scalars
 * pass through untouched for the same "no known PII shape" reason. Objects
 * (embedded-resource / image / other adapter response DTOs) ALSO pass through
 * untouched rather than being walked reflectively — this hook only knows the
 * `Redactor`'s flat/nested-array masking rule, and blindly mutating an
 * adapter-owned DTO object risks breaking its own (adapter-version-specific)
 * invariants; a pack that truly needs DTO-shaped redaction needs a
 * tool/DTO-aware hook of its own, not this generic one.
 *
 * Governed-namespace scoping matches {@see AbilityAuditLog} and
 * {@see AbilityPermissionGate}: redact only when the call's ability id falls
 * under one of `$governedNamespaces`; an empty list (no integration active)
 * redacts nothing, same fail-safe default as those two seams. The ability id
 * is read off `$mcp_tool->get_observability_context()['ability_name']` (the
 * SAME field {@see McpRequestAuditHandler} keys its own governance-adjacent
 * decisions on via the `mcp.request` tags) rather than through
 * `Integrations\Woo\VerbMapper`'s tool-name convention — that mapper encodes
 * Woo's OWN "namespace-resource-action" hyphen rule and is deliberately
 * Woo-specific; this hook has no reason to assume any particular tool-naming
 * convention when the adapter already hands back the real ability id for any
 * ability-backed tool (`McpTool::fromAbility()` sets
 * `observability_context['ability_name'] = $ability->get_name()` upstream).
 * A non-ability-backed tool (no `ability_name` in that context, or a foreign
 * `$mcp_tool` shape without the accessor at all) falls back to the raw MCP
 * tool name, which will not match a governed ability-id prefix — so it is
 * simply never redacted, same fail-safe default as an unmatched prefix.
 *
 * Priority: registered at {@see self::PRIORITY} (PHP_INT_MAX) so this runs
 * LAST among any `mcp_adapter_tool_call_result` subscribers — redacting what
 * actually leaves the filter chain, not an intermediate shape a later
 * (content-enrichment, etc.) subscriber might still rewrite.
 *
 * Wired in bootstrap exactly like {@see McpRequestAuditHandler} and
 * {@see PreToolCallGate}: dormant no-op unless a real mcp-adapter carrying
 * this filter is the loaded copy — the filter simply never fires without it.
 */
final class ToolCallResultRedactor
{
    private const PRIORITY = PHP_INT_MAX;

    /**
     * @param list<string> $governedNamespaces Ability-id prefixes this hook
     *                                          redacts for (contributed by
     *                                          integrations + the
     *                                          `agent_safety_governed_namespaces`
     *                                          filter). Empty => inert no-op.
     */
    public function __construct(
        private readonly PackResolver $packs,
        private readonly array $governedNamespaces = [],
    ) {
    }

    public function register(): void
    {
        add_filter('mcp_adapter_tool_call_result', [$this, 'redact'], self::PRIORITY, 5);
    }

    /**
     * @param mixed                 $result  The raw execution result (may be WP_Error, a scalar, an array, or an object).
     * @param array<string, mixed>  $args    The tool arguments used (unused here; part of the filter's fixed signature).
     * @param mixed                 $mcpTool The MCP tool instance (duck-typed; never assumed to be a specific adapter class).
     * @param mixed                 $server  The MCP server instance (unused here; part of the filter's fixed signature).
     * @return mixed Unchanged for non-array/ungoverned/non-redacting results; recursively masked array otherwise.
     */
    public function redact($result, array $args, string $toolName, $mcpTool = null, $server = null)
    {
        if (!is_array($result)) {
            return $result;
        }

        if (!$this->isGoverned($this->abilityName($toolName, $mcpTool))) {
            return $result;
        }

        return Redactor::apply($result, $this->packs->resolve()->redactsPii());
    }

    /**
     * The real ability id for an ability-backed tool, when the adapter's
     * `$mcp_tool` exposes it — the SAME field {@see McpRequestAuditHandler}
     * reads off the `mcp.request` tags. Duck-typed (no upstream class
     * dependency), null-guarded at every hop, so a foreign/older `$mcp_tool`
     * shape (or a plain fixture in tests) is read as "unknown" rather than
     * fatal, falling back to the raw MCP tool name.
     *
     * @param mixed $mcpTool
     */
    private function abilityName(string $toolName, $mcpTool): string
    {
        if (!is_object($mcpTool) || !method_exists($mcpTool, 'get_observability_context')) {
            return $toolName;
        }

        $context = $mcpTool->get_observability_context();
        $abilityName = is_array($context) ? ($context['ability_name'] ?? null) : null;

        return is_string($abilityName) && $abilityName !== '' ? $abilityName : $toolName;
    }

    /** Is this ability name under one of the governed namespace prefixes? */
    private function isGoverned(string $name): bool
    {
        foreach ($this->governedNamespaces as $namespace) {
            if ($namespace !== '' && str_starts_with($name, $namespace)) {
                return true;
            }
        }

        return false;
    }
}
