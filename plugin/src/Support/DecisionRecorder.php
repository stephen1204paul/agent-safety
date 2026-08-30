<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Approval\ApprovalStore;
use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;
use Specflux\AgentSafety\Audit\Redactor;
use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Packs\Pack;

/**
 * Decision-side recording shared by BOTH gate seams — the live Abilities-API
 * permission_callback ({@see \Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate})
 * and the forward-compat mcp_adapter_pre_tool_call seam
 * ({@see \Specflux\AgentSafety\Plugin\Hooks\PreToolCallGate}).
 *
 * Owning these here is what keeps the seams from DIVERGING: a denial or a pending
 * approval is audited and persisted identically no matter which seam intercepts a
 * call first.
 *
 * Deliberately NO reserve/finalize: the reserve→finalize/rollback lifecycle is
 * single-owned by the execution seam (the permission_callback runs on every
 * adapter version). This recorder only PEEKS at grants (non-mutating), so an
 * already-approved retry can be admitted by an earlier seam and flow to that one
 * lifecycle — a grant is never claimed twice.
 */
final class DecisionRecorder
{
    /**
     * Verdicts already audited THIS REQUEST, keyed by everything that makes a
     * verdict distinct. WordPress re-enters ability permission callbacks many
     * times per REST request (route matching, Allow-header probing, the
     * ability's own execute-time re-check — ~11 invocations observed live on
     * WP 7.0), and every re-entry re-derives the same verdict. The decision
     * gates memoize their SIDE EFFECTS (quota, approvals, notifications), so
     * without this guard the audit append was the one path still firing per
     * invocation: one denied HTTP call wrote ~11 identical rows (found by
     * live smoke test 2026-07-14). Living here, the guard also covers both
     * seams at once — a call intercepted by PreToolCallGate AND the
     * permission callback still audits exactly once.
     *
     * @var array<string, true>
     */
    private array $audited = [];

    public function __construct(
        private readonly ?AuditSink $sink = null,
        private readonly ?ApprovalStore $approvals = null,
    ) {
    }

    /**
     * Emit a gate-decision audit record for a NON-executing verdict — a
     * denial or a pending approval. Allowed calls are audited at execution time by
     * {@see \Specflux\AgentSafety\Plugin\Hooks\AbilityAuditLog}. No-op without a sink.
     * $shadow = true marks a shadow-mode verdict (observed, not enforced —
     * the call DID execute) via the record's dry_run field.
     *
     * @param array<string, mixed> $input
     */
    public function auditDecision(string $eventId, string $verb, array $input, Pack $pack, Decision $decision, ?string $approvalId = null, bool $shadow = false): void
    {
        if ($this->sink === null) {
            return;
        }

        $memoKey = implode('|', [
            $pack->name,
            (string) RequestContext::tokenId(),
            $verb,
            md5(serialize($input)),
            $decision->outcome->name,
            $decision->reason,
            $shadow ? '1' : '0',
        ]);
        if (isset($this->audited[$memoKey])) {
            return;
        }
        $this->audited[$memoKey] = true;

        $this->sink->append(AuditRecord::decision(
            id: $eventId,
            ts: RequestContext::nowUtc(),
            correlationId: RequestContext::correlation(),
            pack: $pack->name,
            actor: RequestContext::actor(),
            ability: $verb,
            tier: $decision->tier?->value,
            input: Redactor::apply($input, $pack->redactsPii()),
            decision: AuditDecision::fromOutcome($decision->outcome),
            approval: $approvalId !== null ? ['id' => $approvalId, 'approver' => null] : null,
            ip: RequestContext::ip(),
            dryRun: $shadow,
            reason: $decision->reason,
        ));
    }

    /**
     * Persist (or reuse) a pending approval for an irreversible verb and return its
     * id; the requesting principal (identity-provider token id) is bound so a by-reference retry can
     * match it, and the audit event id is linked for cross-reference. No-op (null)
     * without an approval store.
     *
     * @param array<string, mixed> $input
     */
    public function requestApproval(string $verb, array $input, string $auditEventId): ?string
    {
        if ($this->approvals === null) {
            return null;
        }

        // AS-11: a host may enrich the human-facing summary (e.g. SenroFlux's
        // rich publish rows). The filter may narrow or reword — it can never
        // touch the verb, the binding hash or the principal, so it can never
        // loosen what the approval binds to.
        //
        // The flat summary interpolates RAW AGENT ARGUMENTS, so it is never
        // markup. Only a value the filter actually changed is host-authored,
        // and only that one is tagged for markup rendering on the approval
        // screen ({@see SummaryMarkup}); anything else — including a filter
        // that returned its input unchanged, or a non-string — stays plain
        // text and is escaped there. A filter that splices agent argument
        // values into its own markup therefore owns escaping them: it has
        // opted that row into markup rendering.
        $flat = $this->summarize($verb, $input);
        $filtered = apply_filters('agent_safety_approval_summary', $flat, $verb, $input);
        $summary = is_string($filtered) && $filtered !== $flat
            ? SummaryMarkup::wrap($filtered)
            : $flat;

        return $this->approvals->request(
            $verb,
            ApprovalBinding::hash($verb, $input),
            $summary,
            RequestContext::correlation(),
            $auditEventId,
            RequestContext::tokenId(),
        );
    }

    /**
     * Non-mutating: is there an approved, unexpired grant for this exact action — by
     * bearer token (delegation) or by-reference (same principal)? Lets an
     * already-approved retry proceed WITHOUT claiming the grant, so the execution
     * seam remains the sole place a grant is reserved. False without a store.
     *
     * @param array<string, mixed> $input
     */
    public function hasApprovedGrant(string $verb, array $input): bool
    {
        if ($this->approvals === null) {
            return false;
        }

        $token = isset($input[ApprovalBinding::TOKEN_ARG]) ? (string) $input[ApprovalBinding::TOKEN_ARG] : '';

        return $this->approvals->peekApproved(
            $token !== '' ? $token : null,
            $verb,
            ApprovalBinding::hash($verb, $input),
            RequestContext::tokenId(),
        );
    }

    /**
     * A short, human-readable description of the action for the approval screen.
     *
     * @param array<string, mixed> $input
     */
    public function summarize(string $verb, array $input): string
    {
        $parts = [];
        foreach ($input as $k => $v) {
            if ($k === ApprovalBinding::TOKEN_ARG || is_array($v)) {
                continue;
            }
            $parts[] = $k . '=' . (is_scalar($v) ? (string) $v : gettype($v));
        }

        return $verb . ($parts === [] ? '' : ' { ' . implode(', ', array_slice($parts, 0, 8)) . ' }');
    }
}
