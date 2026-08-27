<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Hooks;

use Specflux\AgentSafety\Plugin\Integrations\Woo\VerbMapper;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use Specflux\AgentSafety\Plugin\Verdict\VerdictMode;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use WP_Error;

/**
 * Forward-compat gate seam (code-confirmed against mcp-adapter v0.5.0, the
 * first release to fire it): hooks `mcp_adapter_pre_tool_call`. Returning a
 * WP_Error short-circuits the agent's tool call entirely — so the agent sees
 * the real `approval_required` error data, which `WP_Ability::execute()` would
 * otherwise mask to a generic `ability_invalid_permissions`; returning the
 * args array lets it proceed. Dormant on the shipping stack (WooCommerce 11
 * pins adapter ^0.3.0); the live seam is {@see AbilityPermissionGate}.
 *
 * This is the PEEK-mode adapter of the shared {@see VerdictPipeline}: it feeds
 * the tool's self-reported annotations in as {@see Hints}, never claims a
 * grant (an already-approved retry is detected by a non-mutating peek and
 * allowed to PROCEED so the permission_callback — which runs on every adapter
 * version — stays the single owner of reserve→finalize), and translates the
 * verdict. Same pipeline, same PackResolver, so the two seams can never
 * diverge or double-act on one call (docs/adr/0001-single-verdict-pipeline.md).
 */
final class PreToolCallGate
{
    public function __construct(
        private readonly VerdictPipeline $pipeline,
        private readonly VerbMapper $mapper,
        private readonly PackResolver $packs,
    ) {
    }

    public function register(): void
    {
        add_filter('mcp_adapter_pre_tool_call', [$this, 'handle'], 10, 4);
    }

    /**
     * @param array<string, mixed> $args
     * @param mixed                $mcpTool
     * @param mixed                $server
     * @return array<string, mixed>|WP_Error  Args to proceed, or WP_Error to block.
     */
    public function handle(array $args, string $toolName, $mcpTool = null, $server = null)
    {
        $verdict = $this->pipeline->judge(
            $this->mapper->toVerb($toolName),
            $args,
            $this->packs->resolve(),
            Hints::fromMcpTool($mcpTool),
            VerdictMode::Peek,
        );

        return $verdict->error() ?? $args;
    }
}
