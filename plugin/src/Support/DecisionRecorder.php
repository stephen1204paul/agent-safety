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
    public function __construct(
        private readonly ?AuditSink $sink = null,
        private readonly ?ApprovalStore $approvals = null,
    ) {
    }

    /**
     * Emit a gate-decision audit record (SPEC §5) for a NON-executing verdict — a
     * denial or a pending approval. Allowed calls are audited at execution time by
     * {@see \Specflux\AgentSafety\Plugin\Hooks\AbilityAuditLog}. No-op without a sink.
     *
     * @param array<string, mixed> $input
     */
    public function auditDecision(string $eventId, string $verb, array $input, Pack $pack, Decision $decision, ?string $approvalId = null): void
    {
        if ($this->sink === null) {
            return;
        }

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

        return $this->approvals->request(
            $verb,
            ApprovalBinding::hash($verb, $input),
            $this->summarize($verb, $input),
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
