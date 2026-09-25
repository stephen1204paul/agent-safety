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
 *   reserve()  → stale      (AS-6: the target's state fingerprint no longer matches the
 *                            one captured at request time — never claimed; see
 *                            {@see ReserveOutcome} and request()'s pending-dedupe rule below)
 *   (host-only)→ void_environment (AS-7: an approved-but-unclaimed row voided by a
 *                            site-binding mismatch — terminal, never claimed; reserve()
 *                            reports it via {@see ReserveOutcome::voidEnvironment()})
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
 *   status (pending|approved|in_flight|consumed|rejected|expired|stale|void_environment), approver (?int),
 *   fingerprint (?string), fingerprint_kind (probe|none|grant|null), probe_args (?string,
 *   canonical JSON — see $probeArgs below),
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
     * AS-6: $fingerprintKind is one of `probe` (a host {@see \Specflux\AgentSafety\Plugin\Approval\StateProbe}
     * produced $fingerprint), `none` (the verb declares no probe), or `grant`
     * (the row was minted under a pre-approval grant, never fingerprinted). A
     * request whose (verb, args_hash, subject) matches an existing NON-EXPIRED
     * **pending** row, but whose fresh $fingerprint differs from that row's
     * stored one (both kind `probe`), marks the old row `stale` and inserts a
     * fresh pending row instead of reusing it (the "pending dedupe" rule,
     * AS-6 §3.3 item 7).
     *
     * Security fix (post-AS-6): $probeArgs is the canonical-JSON encoding of
     * {@see \Specflux\AgentSafety\Plugin\Approval\StateProbe::targetArgs()}'s
     * return value — the EXACT subset of the real call arguments the probe
     * reads — captured only when $fingerprintKind is `probe`. The approve-time
     * re-probe decodes this instead of parsing the human-readable summary, so
     * an attacker can no longer steer which object gets re-probed by planting
     * an `id=` look-alike in a free-text argument the summary happens to
     * interpolate.
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
        ?string $fingerprint = null,
        string $fingerprintKind = 'none',
        ?string $probeArgs = null,
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
     * (approved → in_flight), returning a {@see ReserveOutcome}. Matches by bearer
     * $token when given, else by $subject (by-reference). Atomicity guarantees a
     * single grant can be reserved at most once concurrently, so it can never
     * drive two executions of an irreversible verb.
     *
     * AS-6: when the matched row's fingerprint_kind is `probe` and
     * $currentFingerprint does not match the row's stored fingerprint
     * (hash_equals), the target changed since the approval was granted — the
     * row is flipped straight to the terminal `stale` status instead of being
     * reserved, and {@see ReserveOutcome::stale()} is returned so the caller
     * can file a fresh pending request carrying the current fingerprint.
     */
    public function reserve(?string $token, string $verb, string $argsHash, ?string $subject, ?string $currentFingerprint = null): ReserveOutcome;

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
