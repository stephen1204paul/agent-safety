<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Approval;

/**
 * Persistence contract for pre-approval grants (AS-12). Same dependency
 * direction as {@see ApprovalStore}: the core defines the seam, the host
 * implements it over real storage.
 *
 * A grant is keyed by an OPAQUE correlation id plus a subject, and nothing
 * else. The core deliberately learns nothing about what a host scopes a
 * correlation to — a run, a session, a batch job — so this seam can never
 * grow host- or workflow-specific knowledge.
 *
 * Lifecycle of one grant:
 *
 *   issue()              → active     (a human pre-authorised N calls of one verb)
 *   reserve()            → active     (one reservation spent; remaining_count - 1)
 *                        → exhausted  (…when that took remaining_count to 0)
 *   release()            → active     (the reserved call did NOT execute; count restored)
 *   revokeByCorrelation()→ revoked    (withdrawn wholesale — terminal)
 *   (TTL lapses)         → expired    (terminal; a hard wall independent of the count)
 *
 * The reserve → release split mirrors ApprovalStore's reserve → rollback: a
 * reservation is only truly SPENT once the action executed. A host that
 * reserves and then finds the call did not run MUST release, or the human's
 * budget is silently consumed by an action that never happened.
 *
 * Every implementation MUST honour {@see Grant::canReserve()} — that pure rule
 * is the contract; storage-level guards (a conditional UPDATE) exist for
 * atomicity, never to decide something different.
 */
interface GrantStore
{
    /**
     * Record a new grant authorising up to $count calls of $verb for $subject
     * inside $correlationId, and return its id ("gnt_…").
     *
     * @param string  $verb          The single verb authorised (no globbing).
     * @param int     $count         How many calls; MUST be >= 1 (implementations fail closed otherwise).
     * @param ?string $subject       Authenticated principal; an empty/null subject can never match.
     * @param string  $correlationId Opaque host-set scope key.
     * @param ?int    $grantedBy     Opaque id of the granting human.
     * @param ?string $planStepId    Host-side provenance for the decision.
     */
    public function issue(
        string $verb,
        int $count,
        ?string $subject,
        string $correlationId,
        ?int $grantedBy,
        ?string $planStepId,
    ): string;

    /**
     * Atomically claim ONE reservation from an active, unexpired grant matching
     * ($correlationId, $verb, $subject), decrementing its remaining count and
     * sealing it as `exhausted` when that reaches 0. Returns the grant id, or
     * null when nothing matches — including when $subject is null or empty.
     */
    public function reserve(string $correlationId, string $verb, ?string $subject): ?string;

    /**
     * Give back a reservation the call did NOT execute against (count + 1, and
     * an `exhausted` grant returns to `active`). Idempotent-safe to call on an
     * unknown id. MUST NOT resurrect a revoked or expired grant.
     */
    public function release(string $grantId): void;

    /**
     * Withdraw EVERY grant in one correlation scope, whatever its remaining
     * count. Terminal: a revoked grant can never authorise another call.
     */
    public function revokeByCorrelation(string $correlationId): void;

    /** Read one grant's current state; null when the id is unknown. */
    public function get(string $grantId): ?Grant;
}
