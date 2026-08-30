<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Verdict;

use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Approval\GrantStore;
use Specflux\AgentSafety\Plugin\Approval\ApprovalMinter;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\GrantRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;

/**
 * The pre-approval path of the {@see VerdictPipeline} (AS-12), kept in its own
 * object so the pipeline's ordered evaluation gains exactly one branch and the
 * whole feature can be absent (a null collaborator) without touching it.
 *
 * It runs ONLY after the ordinary exact-approval claim has already failed, and
 * only in {@see VerdictMode::Claim}: minting is a side effect, and the peek-mode
 * seam exists precisely to decide WITHOUT side effects. A peeking seam therefore
 * parks the call as `approval_required`, which is the stricter answer.
 *
 * FOUR independent things must all be true before a grant authorises anything:
 *
 *  1. `agent_safety_enable_grants` returns exactly `true`. The whole feature is
 *     off by default; an unhooked site behaves as if AS-12 did not ship.
 *  2. There is an authenticated subject. An empty one never matches
 *     ({@see \Specflux\AgentSafety\Approval\Grant::canReserve()}).
 *  3. An active grant exists for (correlation, verb, subject) with count left
 *     and its TTL intact.
 *  4. `agent_safety_grant_eligible` returns exactly `true` — DEFAULT FALSE.
 *
 * Point 4 is the one that makes object binding safe. A grant is per-verb, not
 * per-object, so the host — which knows which objects the human actually
 * accepted — is the only thing that can say whether THESE arguments are inside
 * the human's decision. Defaulting the filter to false means a missing hook (the
 * harness deactivated, a priority slip, a fatal in the callback's file) yields
 * "no grant applies" rather than "every grant applies to any object". A filter
 * may narrow, never widen.
 *
 * Ordering note: the grant is RESERVED before the eligibility filter runs, and
 * released again when the filter says no. The {@see GrantStore} seam exposes no
 * non-mutating peek by design — reserve is the atomic single-claim operation —
 * so reserving first is what keeps two concurrent calls from both believing the
 * same last reservation is theirs. A released reservation restores the count
 * exactly, so an ineligible call costs a human's budget nothing.
 */
final class GrantGate
{
    public function __construct(
        private readonly ?GrantStore $grants = null,
        private readonly ?ApprovalMinter $minter = null,
        private readonly ?DecisionRecorder $recorder = null,
        private readonly GrantRecorder $audit = new GrantRecorder(),
    ) {
    }

    /**
     * Is the grants feature switched on for this site? Anything other than a
     * literal `true` reads as off, so a filter returning a truthy string or 1 by
     * accident cannot enable a security feature.
     */
    public static function enabled(): bool
    {
        return true === apply_filters('agent_safety_enable_grants', false);
    }

    /** Wired at all? A gate without a store or a minter can never authorise anything. */
    public function available(): bool
    {
        return $this->grants !== null && $this->minter !== null && $this->recorder !== null;
    }

    /**
     * Try to satisfy this call from a pre-approval grant. Returns the id of the
     * grant that was spent — so the caller can RELEASE it if the action never
     * executes — or null when the call must park as usual.
     *
     * On success an already-approved approval bound to these exact args now
     * exists, so the caller's ordinary reserve → finalize/rollback path claims
     * it like any other human grant.
     *
     * @param array<string, mixed> $args
     */
    public function mint(string $verb, array $args): ?string
    {
        if (!$this->available() || !self::enabled()) {
            return null;
        }

        $subject = RequestContext::tokenId();
        if ($subject === null || $subject === '') {
            return null;
        }

        $correlationId = RequestContext::correlation();
        $grantId = $this->grants?->reserve($correlationId, $verb, $subject);
        if ($grantId === null) {
            return null;
        }

        // From here the reservation is SPENT, so every exit other than a
        // successful mint must give it back — including one through a Throwable.
        // The eligibility filter is host code: a fatal-adjacent exception in it
        // must not quietly charge a human's budget for a call that never ran.
        $spent = true;
        try {
            // Read back the POST-decrement grant: the eligibility filter sees the
            // budget as it now stands, and exhaustion is observable without a
            // second rule about what reserve() returned.
            $grant = $this->grants?->get($grantId);
            if ($grant === null) {
                return null;
            }

            /** @var mixed $eligible */
            $eligible = apply_filters('agent_safety_grant_eligible', false, $grant, $verb, $args);
            if (true !== $eligible) {
                return null;
            }

            $approvalId = $this->minter?->mintApproved(
                $verb,
                ApprovalBinding::hash($verb, $args),
                (string) $this->recorder?->summaryFor($verb, $args),
                $correlationId,
                RequestContext::event(),
                $subject,
                $grant->grantedBy,
                $grantId,
            );

            // The write failed: give the reservation back rather than charging a
            // human's budget for an authorisation that does not exist.
            if ($approvalId === null) {
                return null;
            }

            $spent = false;

            if ($grant->isExhausted()) {
                $this->audit->exhausted($grant);
            }

            return $grantId;
        } finally {
            if ($spent) {
                $this->release($grantId);
            }
        }
    }

    /**
     * Give a reservation back — the call it was spent on did not execute. Safe
     * to call with null (nothing was reserved) so callers need no guard.
     */
    public function release(?string $grantId): void
    {
        if ($grantId === null || $grantId === '') {
            return;
        }

        $this->grants?->release($grantId);
    }
}
