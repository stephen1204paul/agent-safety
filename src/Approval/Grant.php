<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Approval;

/**
 * A pre-approval: one human decision that authorises up to N future calls of
 * ONE verb, for ONE subject, inside ONE correlation scope (AS-12).
 *
 * Where an {@see ApprovalStore} record binds to one EXACT action (verb + args
 * hash), a Grant deliberately does not bind to args — that is what makes it
 * usable ahead of time, and exactly why it is fenced on every other axis:
 *
 *  - `correlationId` — an opaque scope key the HOST sets (never derived from
 *    agent- or HTTP-controlled input). The core attaches no meaning to it: it
 *    is a string that must match, nothing more. The core knows nothing about
 *    what a host puts in it (a run id, a session, a batch) by design.
 *  - `subject` — the authenticated principal the grant was issued for. An
 *    EMPTY subject can never match, mirroring {@see ApprovalStore::reserve()}:
 *    a grant with no principal would otherwise be a grant to anyone.
 *  - `remainingCount` — how many reservations are left; 0 seals it.
 *  - `expiresTs` — a hard wall-clock TTL, independent of the count.
 *
 * Immutable and clock-free: the host injects every timestamp as a UTC string
 * ('Y-m-d H:i:s') and passes "now" into {@see canReserve()}, so the whole
 * usability rule is a pure function and is unit-testable without a database.
 * That rule is the single source of truth a store MUST honour; a SQL-backed
 * store repeats the same conditions in its conditional UPDATE for atomicity,
 * never to decide something different.
 */
final class Grant
{
    /**
     * @param string       $grantId        Opaque id ("gnt_…").
     * @param string       $correlationId  Host-set scope key; matching is exact-string.
     * @param string       $verb           The one verb this grant authorises.
     * @param int          $remainingCount Reservations left; 0 = exhausted.
     * @param ?string      $subject        Authenticated principal; null/'' never matches.
     * @param ?int         $grantedBy      Opaque id of the human who granted it (host user id).
     * @param ?string      $planStepId     Host-side provenance: which plan step the human accepted.
     * @param string       $createdTs      UTC 'Y-m-d H:i:s'.
     * @param string       $expiresTs      UTC 'Y-m-d H:i:s'; hard TTL wall.
     * @param ?string      $revokedTs      UTC 'Y-m-d H:i:s' when withdrawn, else null.
     */
    public function __construct(
        public readonly string $grantId,
        public readonly string $correlationId,
        public readonly string $verb,
        public readonly int $remainingCount,
        public readonly ?string $subject,
        public readonly ?int $grantedBy,
        public readonly ?string $planStepId,
        public readonly string $createdTs,
        public readonly string $expiresTs,
        public readonly ?string $revokedTs,
        public readonly GrantStatus $status,
    ) {
    }

    /**
     * May this grant authorise ONE more call of $verb for $subject in
     * $correlationId, as of $nowUtc ('Y-m-d H:i:s', UTC)?
     *
     * Fail-closed by construction — every clause must hold:
     *   1. status is `active` (never exhausted/expired/revoked),
     *   2. it was not revoked out-of-band,
     *   3. reservations remain,
     *   4. the hard TTL has not lapsed (compared as UTC strings, which sort
     *      lexicographically in this format — no timezone maths, no clock read),
     *   5. the correlation scope matches EXACTLY,
     *   6. the verb matches EXACTLY (no globbing: a grant is per-verb),
     *   7. the subject is non-empty on BOTH sides and matches exactly.
     *
     * Clause 7 is the "empty subject never matches" rule: an unauthenticated
     * caller (no token id) must never inherit a grant, and a grant persisted
     * without a principal must never be claimable by one.
     */
    public function canReserve(string $correlationId, string $verb, ?string $subject, string $nowUtc): bool
    {
        if ($this->status !== GrantStatus::Active || $this->revokedTs !== null) {
            return false;
        }

        if ($this->remainingCount < 1) {
            return false;
        }

        if ($this->expiresTs <= $nowUtc) {
            return false;
        }

        if ($correlationId === '' || $this->correlationId !== $correlationId) {
            return false;
        }

        if ($this->verb !== $verb) {
            return false;
        }

        if ($subject === null || $subject === '' || $this->subject === null || $this->subject === '') {
            return false;
        }

        return $this->subject === $subject;
    }

    /** True once every reservation has been spent. */
    public function isExhausted(): bool
    {
        return $this->status === GrantStatus::Exhausted || $this->remainingCount < 1;
    }
}
