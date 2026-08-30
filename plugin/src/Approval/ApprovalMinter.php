<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Approval;

/**
 * The seam a pre-approval grant uses to turn itself into ONE already-approved
 * {@see \Specflux\AgentSafety\Approval\ApprovalStore} record, bound to the real
 * call arguments (AS-12).
 *
 * Deliberately a HOST interface, not part of the core ApprovalStore contract:
 * minting is where grants and approvals meet, and the core must not learn that
 * grants exist. The core seam keeps exactly the lifecycle it had —
 * request → approve → reserve → finalize/rollback — and a grant simply supplies
 * the "approve" step from a decision the human already made, so from the gate's
 * point of view the ordinary reserve/finalize/rollback path runs unchanged.
 *
 * A minted record is by-reference ONLY: no bearer token is generated, because
 * there is no human in the loop at mint time to show one to, and a token nobody
 * received is a credential nobody can revoke.
 */
interface ApprovalMinter
{
    /**
     * Write an already-approved, time-bounded approval for this exact action and
     * return its id, or null when nothing was written.
     *
     * @param string  $verb          The governed verb.
     * @param string  $argsHash      {@see \Specflux\AgentSafety\Approval\ApprovalBinding::hash()} of the REAL args.
     * @param string  $summary       Human-facing summary (already through the AS-11 filter).
     * @param string  $correlationId The scope the grant was matched in.
     * @param string  $auditEventId  Audit event this mint is cross-referenced by.
     * @param ?string $subject       The principal the grant was issued to; the record binds to it.
     * @param ?int    $approver      The human who granted — the GRANTOR, not the agent.
     * @param ?string $grantId       Provenance: which grant authorised this row.
     */
    public function mintApproved(
        string $verb,
        string $argsHash,
        string $summary,
        string $correlationId,
        string $auditEventId,
        ?string $subject,
        ?int $approver,
        ?string $grantId,
    ): ?string;
}
