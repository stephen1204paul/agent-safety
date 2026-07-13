<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Approval;

/**
 * Persistence contract for the async approval flow. The core defines the
 * seam; the host implements it over real storage ($wpdb). Keeping it an interface
 * preserves a clean dependency direction: the gate depends on this
 * abstraction, never on WordPress.
 *
 * Lifecycle of one approval record:
 *
 *   request()  → pending    (agent tried an irreversible verb; awaits a human)
 *   approve()  → approved   (human grants; time-bounded)   [host-only]
 *   reject()   → rejected   (human denies)                 [host-only]
 *   reserve()  → in_flight  (agent retries; the grant is claimed for ONE execution)
 *   finalize() → consumed   (the action actually executed — terminal)
 *   rollback() → approved   (the action did NOT execute — the grant is released so a
 *                            retry within the original TTL can reuse it)
 *
 * The reserve→finalize/rollback split is the "consume on execution success, not on
 * attempt" property: a token is only spent once the irreversible action truly ran,
 * yet a single approval can never drive two executions (reserve is atomic).
 *
 * Two delivery modes share one record, decided by reserve()'s arguments:
 *   - by-reference: the same authenticated principal ($subject) that requested the
 *     action simply retries the identical call — no token round-trips out-of-band.
 *   - by-token (bearer): the minted single-use token is presented, for delegation
 *     to a different actor.
 *
 * Record shape (associative array) returned by the host's pending()/get():
 *   approval_id, verb, args_hash, summary, correlation_id, audit_event_id, key_id,
 *   status (pending|approved|in_flight|consumed|rejected|expired), approver (?int),
 *   created_ts, pending_expires_ts, expires_ts, consumed_ts.
 */
interface ApprovalStore
{
    /**
     * Record (or return the existing) pending approval for one exact action. MUST be
     * idempotent per (verb, args_hash, subject) while a non-expired pending row
     * exists, so an agent that retries before a human acts does not spawn duplicate
     * requests.
     *
     * @param ?string $subject The authenticated principal that requested the action
     *                         (host: a namespaced identity-provider token id, e.g.
     *                         "app:{uuid}" or "wc:key_7"). Bound to the record so a
     *                         by-reference reserve can match the same principal.
     * @return string The approval id (e.g. "apr_…").
     */
    public function request(
        string $verb,
        string $argsHash,
        string $summary,
        string $correlationId,
        string $auditEventId,
        ?string $subject,
    ): string;

    /**
     * Non-mutating check: does an approved, unexpired grant exist for this exact
     * action? Matches by bearer $token when given, else by $subject (by-reference) —
     * the SAME match rule as {@see reserve()}, but it claims NOTHING.
     *
     * This is what lets an earlier gate seam admit an already-approved retry and
     * hand it on to the single execution seam that owns reserve→finalize, so a
     * grant is never reserved (and thus an irreversible action never authorised)
     * twice across two seams.
     */
    public function peekApproved(?string $token, string $verb, string $argsHash, ?string $subject): bool;

    /**
     * Atomically claim an approved, unexpired grant for ONE execution
     * (approved → in_flight), returning its approval id, or null when nothing
     * matches. Matches by bearer $token when given, else by $subject (by-reference).
     * Atomicity guarantees a single grant can be reserved at most once concurrently,
     * so it can never drive two executions of an irreversible verb.
     */
    public function reserve(?string $token, string $verb, string $argsHash, ?string $subject): ?string;

    /**
     * Mark a reserved grant as truly spent (in_flight → consumed) once the action
     * executed. Terminal; idempotent (a no-op if the row is no longer in_flight).
     */
    public function finalize(string $approvalId): void;

    /**
     * Release a reserved grant the action did NOT execute against
     * (in_flight → approved), so a retry within the original TTL can reuse it.
     * Idempotent.
     */
    public function rollback(string $approvalId): void;
}
