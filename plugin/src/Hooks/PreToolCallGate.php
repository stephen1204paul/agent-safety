<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Hooks;

use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\GateContext;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Integrations\Woo\VerbMapper;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use WP_Error;

/**
 * Forward-compat gate seam (SPEC §1, code-confirmed mcp-adapter v0.5.0): hooks
 * `mcp_adapter_pre_tool_call`. Returning a WP_Error short-circuits the agent's
 * tool call entirely; returning the args array lets it proceed. (Dormant on the
 * shipping stack — Woo 10.8.1 vendors adapter 0.1.0, which never fires this
 * filter; the live seam is {@see AbilityPermissionGate}.)
 *
 * Division of labour with the execution seam, so the two NEVER diverge or
 * double-act on one call:
 *   - Both resolve the pack via the SAME {@see PackResolver} and record decisions
 *     via the SAME {@see DecisionRecorder} (identical audit + pending rows).
 *   - A verdict this seam SHORT-CIRCUITS (deny / approval-required) is audited and
 *     persisted HERE, because short-circuiting means the execution seam never runs
 *     for that call.
 *   - The reserve→finalize lifecycle is NOT done here. An already-approved retry is
 *     detected by a NON-mutating peek and allowed to PROCEED; the permission_callback
 *     (which runs on every adapter version) is the single owner that reserves the
 *     grant and spends it on execution success. So a grant is never claimed twice.
 */
final class PreToolCallGate
{
    public function __construct(
        private readonly Gate $gate,
        private readonly VerbMapper $mapper,
        private readonly PackResolver $packs,
        private readonly DecisionRecorder $recorder,
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
        $verb = $this->mapper->toVerb($toolName);
        $pack = $this->resolvePack();

        $decision = $this->gate->evaluate(new GateContext(
            verb: $verb,
            args: $args,
            pack: $pack,
            selfReportedReadonly: $this->isSelfReportedReadonly($mcpTool),
            hasValidApproval: $this->recorder->hasApprovedGrant($verb, $args),
        ));

        // Allowed (incl. an already-approved retry): proceed. Execution audit and the
        // reserve→finalize of any grant happen in the permission_callback seam.
        if (Outcome::Allow === $decision->outcome) {
            return $args;
        }

        // This call will NOT execute (deny / approval-required). Persist a pending
        // approval where required and audit the verdict HERE — the execution seam,
        // which would otherwise own this, never runs once we short-circuit.
        $eventId = RequestContext::event();
        $approvalId = Outcome::ApprovalRequired === $decision->outcome
            ? $this->recorder->requestApproval($verb, $args, $eventId)
            : null;
        $this->recorder->auditDecision($eventId, $verb, $args, $pack, $decision, $approvalId);

        return Outcome::Deny === $decision->outcome
            ? new WP_Error(
                'agent_safety_denied',
                sprintf('Blocked by Agent Safety (%s): %s', $pack->name, $decision->reason),
                ['status' => 403, 'verb' => $verb, 'tier' => $decision->tier?->value]
            )
            : $this->approvalError($verb, $decision, $approvalId);
    }

    private function approvalError(string $verb, Decision $decision, ?string $approvalId): WP_Error
    {
        return new WP_Error(
            'approval_required',
            sprintf('"%s" is irreversible and requires human approval before it can run. A request has been logged for review.', $verb),
            array_filter([
                'status' => 202,
                'verb' => $verb,
                'tier' => $decision->tier?->value,
                'approval_id' => $approvalId,
            ], static fn ($v) => $v !== null)
        );
    }

    /**
     * Resolve the calling identity to a Capability Pack via the shared
     * {@see PackResolver} — the SAME registry + bindings the live Abilities-API
     * seam uses (keyed on the authenticated WC API key, SPEC §3 / D20). Keeping
     * one resolver means this forward-compat seam can never apply a stale pack
     * that diverges from the admin-configured bindings.
     */
    private function resolvePack(): Pack
    {
        return $this->packs->resolve();
    }

    private function isSelfReportedReadonly($mcpTool): bool
    {
        // STUB: read the tool/ability annotation once the accessor is wired.
        return false;
    }
}
