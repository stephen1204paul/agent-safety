<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Approval\Grant;
use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;

/**
 * Audit emission for the pre-approval grant lifecycle (AS-12).
 *
 * A grant is the one place where a human's decision outruns the action it
 * authorises: by the time a governed call is auto-approved, the human who
 * allowed it may be hours gone. The audit trail therefore has to answer three
 * questions on its own — who granted what and how much, when it was withdrawn,
 * and when the budget ran out — which is exactly these three events.
 *
 * They ride the EXISTING record shape rather than a new one: the hash chain
 * commits to the canonical field order, so adding a top-level field would
 * change the payload of every record type. Instead the event name goes in
 * `reason` (`grant.issued` / `grant.revoked` / `grant.exhausted`), the grant id
 * and grantor go in the `approval` slot — a grant is an approval's precursor,
 * and the slot already means "the authorisation this row is about" — and the
 * count and plan-step provenance go in `input`. `decision` is the single
 * {@see AuditDecision::Grant} value, so one predicate selects every grant event
 * while `reason` still tells the three apart.
 *
 * No-op without a sink, like {@see DecisionRecorder}.
 */
final class GrantRecorder
{
    public const EVENT_ISSUED = 'grant.issued';
    public const EVENT_REVOKED = 'grant.revoked';
    public const EVENT_EXHAUSTED = 'grant.exhausted';

    /**
     * Synthetic pack name for grant events. A grant is issued out of band —
     * before any call, so before any pack has been resolved — and revoked from a
     * run's terminal path, where there is no call either. Naming the subsystem
     * keeps the NOT NULL column honest instead of guessing a pack.
     */
    public const PACK = 'grants';

    /** Verb recorded for a scope-wide revoke, which is about no single verb. */
    private const ALL_VERBS = '*';

    public function __construct(private readonly ?AuditSink $sink = null)
    {
    }

    /** A human pre-authorised $grant->remainingCount calls of one verb. */
    public function issued(Grant $grant): void
    {
        $this->append(
            self::EVENT_ISSUED,
            $grant->correlationId,
            $grant->verb,
            ['grant_id' => $grant->grantId, 'count' => $grant->remainingCount, 'plan_step_id' => $grant->planStepId],
            $grant->grantId,
            $grant->grantedBy,
        );
    }

    /**
     * Every live grant in one scope was withdrawn. Recorded once per revoking
     * CALL, with how many rows it actually hit — a second revoke affects nothing
     * and its caller is expected not to audit it.
     */
    public function revoked(string $correlationId, int $revokedCount): void
    {
        $this->append(
            self::EVENT_REVOKED,
            $correlationId,
            self::ALL_VERBS,
            ['revoked_count' => $revokedCount],
            null,
            null,
        );
    }

    /** The last reservation was spent: this grant can authorise nothing further. */
    public function exhausted(Grant $grant): void
    {
        $this->append(
            self::EVENT_EXHAUSTED,
            $grant->correlationId,
            $grant->verb,
            ['grant_id' => $grant->grantId, 'count' => 0, 'plan_step_id' => $grant->planStepId],
            $grant->grantId,
            $grant->grantedBy,
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function append(
        string $event,
        string $correlationId,
        string $verb,
        array $input,
        ?string $grantId,
        ?int $grantedBy,
    ): void {
        if ($this->sink === null) {
            return;
        }

        $this->sink->append(AuditRecord::decision(
            id: RequestContext::event(),
            ts: RequestContext::nowUtc(),
            correlationId: $correlationId,
            pack: self::PACK,
            actor: RequestContext::actor(),
            ability: $verb,
            tier: null,
            input: $input,
            decision: AuditDecision::Grant,
            approval: $grantId !== null ? ['id' => $grantId, 'approver' => $grantedBy] : null,
            ip: RequestContext::ip(),
            reason: $event,
        ));
    }
}
