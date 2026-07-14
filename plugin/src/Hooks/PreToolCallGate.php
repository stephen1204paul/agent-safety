<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Hooks;

use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\GateContext;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\ArgumentCapGate;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Integrations\Woo\VerbMapper;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Policy\Tier;
use WP_Error;

/**
 * Forward-compat gate seam (code-confirmed against mcp-adapter v0.5.0): hooks
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
        private readonly RateLimitGate $rateLimits = new RateLimitGate(),
        private readonly ArgumentCapGate $argumentCaps = new ArgumentCapGate(),
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
        $hasValidApproval = $this->recorder->hasApprovedGrant($verb, $args);

        $decision = $this->gate->evaluate(new GateContext(
            verb: $verb,
            args: $args,
            pack: $pack,
            selfReportedReadonly: $this->isSelfReportedReadonly($mcpTool),
            hasValidApproval: $hasValidApproval,
        ));

        // SAFETY-CRITICAL: a self-reported destructiveHint may only TIGHTEN
        // this verdict, never loosen it — see elevateForDestructiveHint().
        $decision = $this->elevateForDestructiveHint(
            $decision,
            $pack,
            $this->isSelfReportedDestructive($mcpTool),
            $hasValidApproval,
        );

        // Rate/quota caps (backlog #16) apply only to a call that would otherwise
        // proceed — a denial must never itself consume quota.
        if (Outcome::Allow === $decision->outcome) {
            $decision = $this->enforceRateLimit($pack, $decision, $verb, $args);
        }

        // Argument-aware caps (roadmap 0.2 "spend limits") bind last, same
        // admitted-calls-only rule. No grant is claimed here — like the
        // approval flow itself, the permission_callback seam is the single
        // owner of reserve→finalize; this seam only peeks ($hasValidApproval).
        if (Outcome::Allow === $decision->outcome) {
            $decision = $this->enforceArgumentCaps($pack, $decision, $verb, $args, $hasValidApproval);
        }

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
     * seam uses (keyed on the authenticated WC API key). Keeping
     * one resolver means this forward-compat seam can never apply a stale pack
     * that diverges from the admin-configured bindings.
     */
    private function resolvePack(): Pack
    {
        return $this->packs->resolve();
    }

    /**
     * SAFETY-CRITICAL: a tool's SELF-REPORTED annotations may only make
     * gating STRICTER, never looser. A destructiveHint === true on a call OUR
     * OWN classifier placed below the approval tier ({@see Tier::Irreversible})
     * is treated as though it HAD classified there: the call now faces
     * whatever the pack demands of an irreversible verb (a hard deny-class
     * wall, or approval). readOnlyHint is deliberately NOT read here — it must
     * never relax anything; its only effect anywhere in this codebase is
     * {@see \Specflux\AgentSafety\Policy\TierClassifier::isReadonlyButWrites()},
     * which can only ADD a "readonly_but_writes" denial, never remove one.
     *
     * Lives here — a post-classify tighten in the gate SEAM — rather than as a
     * core {@see \Specflux\AgentSafety\Policy\ElevationRule}, because the
     * annotation comes from mcp-adapter's $mcp_tool argument to this filter,
     * which the core Gate/GateContext/TierClassifier pipeline never sees (by
     * design, so the core stays framework-agnostic: it only ever sees a verb
     * id and call args, never an adapter-specific tool object).
     *
     * Only ever touches a decision that is currently Allow: a call already
     * Denied or ApprovalRequired for an unrelated reason is already at least
     * as strict as what this method could produce, so it is a no-op there —
     * meaning this method can turn Allow into Deny/ApprovalRequired, but never
     * the reverse, which is what makes the "never relax" rule hold by construction.
     */
    private function elevateForDestructiveHint(
        Decision $decision,
        Pack $pack,
        bool $selfReportedDestructive,
        bool $hasValidApproval,
    ): Decision {
        if (!$selfReportedDestructive || Outcome::Allow !== $decision->outcome || Tier::Irreversible === $decision->tier) {
            return $decision;
        }

        if ($pack->deniesClass(Tier::Irreversible)) {
            return Decision::deny('denied_by_class_destructive_hint', Tier::Irreversible);
        }

        if ($pack->requiresApproval(Tier::Irreversible) && !$hasValidApproval) {
            return Decision::approvalRequired(Tier::Irreversible);
        }

        return Decision::allow(Tier::Irreversible);
    }

    /**
     * Enforce this pack's rate/quota caps (backlog #16) on a decision that is
     * otherwise Allow. Denials never reach here, so a blocked call never
     * consumes quota. Returns the SAME decision when admitted (the call has
     * just been counted against the pack's limits as a side effect), or a Deny
     * naming the tripped limit when the cap is exceeded.
     *
     * @param array<string, mixed> $args
     */
    private function enforceRateLimit(Pack $pack, Decision $decision, string $verb, array $args): Decision
    {
        $tripped = $this->rateLimits->admit($pack, RequestContext::tokenId(), $verb, $args);

        return $tripped === null ? $decision : Decision::deny('rate_limited_' . $tripped, $decision->tier);
    }

    /**
     * Enforce this pack's argument-aware caps (roadmap 0.2 "spend limits") on
     * a decision that is otherwise Allow. Hard trips deny, naming the cap and
     * constraint like rate-limit denials name their window; a tripped
     * approval threshold parks the call as approval-required, and the
     * caller's existing non-Allow branch persists the pending request. An
     * already-approved retry sails through: $hasValidApproval (the caller's
     * non-mutating peek) satisfies the threshold, and hard caps still apply.
     *
     * @param array<string, mixed> $args
     */
    private function enforceArgumentCaps(
        Pack $pack,
        Decision $decision,
        string $verb,
        array $args,
        bool $hasValidApproval,
    ): Decision {
        $check = $this->argumentCaps->check($pack, RequestContext::tokenId(), $verb, $args, $hasValidApproval);
        if ($check->allowed) {
            return $decision;
        }

        if ($check->requiresApproval) {
            // An Allow decision always carries its tier; the fallback exists
            // only to fail closed (treat as irreversible) if that ever changes.
            return Decision::approvalRequired($decision->tier ?? Tier::Irreversible);
        }

        return Decision::deny('argument_cap_' . $check->trippedCap . '_' . $check->constraint, $decision->tier);
    }

    /** @param mixed $mcpTool */
    private function isSelfReportedReadonly($mcpTool): bool
    {
        return true === $this->annotationHint($mcpTool, 'getReadOnlyHint');
    }

    /** @param mixed $mcpTool */
    private function isSelfReportedDestructive($mcpTool): bool
    {
        return true === $this->annotationHint($mcpTool, 'getDestructiveHint');
    }

    /**
     * Reads one boolean hint off $mcpTool's annotations via mcp-adapter's real
     * accessor chain — get_protocol_dto()->getAnnotations()?->{$accessor}() —
     * WITHOUT depending on the mcp-adapter classes: every hop is duck-typed
     * (method_exists) and null-guarded, so a foreign or malformed $mcpTool
     * (a different adapter version, or a plain stdClass in tests) is read as
     * "no hint" rather than fatal.
     *
     * @param mixed $mcpTool
     */
    private function annotationHint($mcpTool, string $accessor): ?bool
    {
        if (!is_object($mcpTool) || !method_exists($mcpTool, 'get_protocol_dto')) {
            return null;
        }

        $dto = $mcpTool->get_protocol_dto();
        if (!is_object($dto) || !method_exists($dto, 'getAnnotations')) {
            return null;
        }

        $annotations = $dto->getAnnotations();
        if (!is_object($annotations) || !method_exists($annotations, $accessor)) {
            return null;
        }

        $value = $annotations->$accessor();

        return is_bool($value) ? $value : null;
    }
}
