<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Api;

use Specflux\AgentSafety\Approval\Grant;
use Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore;
use Specflux\AgentSafety\Plugin\Support\GrantRecorder;
use Specflux\AgentSafety\Plugin\Verdict\GrantGate;

/**
 * The programmatic pre-approval API (AS-12), reached through
 * `agent_safety()->grants()`. The counterpart to {@see Approvals}: that service
 * resolves ONE blocked action after the fact; this one records a human's
 * decision to allow N future actions of one verb inside one scope.
 *
 * THE invariant, as with Approvals: every caller funnels through here, so an
 * issued or revoked grant is one code path — the feature switch, the store, and
 * the audit event that makes the human's decision visible in the trail.
 *
 * No REST route, deliberately. A grant is more powerful than an approval (it
 * authorises actions that have not happened yet), so it is reachable only from
 * server-side code that already has a human's decision in hand. That also means
 * this service does NOT run its own capability check: unlike an approve click,
 * a grant is issued from a host's own workflow — which may legitimately be a
 * cron tick or a WP-CLI run with no current user — so the CALLER owns proving a
 * human authorised it, and issuing without that proof is the caller's bug. The
 * feature switch below is the site-level consent that any of this happens at all.
 */
final class Grants
{
    public function __construct(
        private readonly WpdbGrantStore $store,
        private readonly GrantRecorder $recorder = new GrantRecorder(),
    ) {
    }

    /** Is the grants feature switched on for this site (`agent_safety_enable_grants`)? */
    public function enabled(): bool
    {
        return GrantGate::enabled();
    }

    /**
     * Record a human's pre-approval of up to $count calls of $verb, for the
     * principal $subject, inside the scope $correlationId. Returns the grant id,
     * or null when nothing was issued.
     *
     * Refuses (null, nothing written, nothing audited) when the feature is off,
     * the count is not positive, the subject is empty — a grant with no
     * principal is a grant to anyone — or the scope is empty. The caller is
     * expected to treat null as "the human's decision did not take effect", not
     * as "it probably worked".
     *
     * $correlationId MUST be derived from server-side state the host owns (a run
     * row's id, e.g. "senroflux:run:42") and never from agent-authored arguments
     * or an HTTP parameter: it is half of what the gate matches on.
     */
    public function issue(
        string $verb,
        int $count,
        ?string $subject,
        string $correlationId,
        ?int $grantedBy = null,
        ?string $planStepId = null,
    ): ?string {
        if (!$this->enabled()) {
            return null;
        }

        if ($verb === '' || $count < 1 || $correlationId === '' || $subject === null || $subject === '') {
            return null;
        }

        $grantId = $this->store->issue($verb, $count, $subject, $correlationId, $grantedBy, $planStepId);

        $grant = $this->store->get($grantId);
        if ($grant !== null) {
            $this->recorder->issued($grant);
        }

        return $grantId;
    }

    /**
     * Withdraw every live grant in one scope and report how many this call
     * actually hit. Meant for a host's TERMINAL paths (a run completed, failed,
     * was cancelled): once the work that justified the pre-approval is over, the
     * budget must not outlive it, TTL or no TTL.
     *
     * Safe to call unconditionally and repeatedly — a second revoke affects
     * nothing and audits nothing — so a host can put it on every terminal path
     * without tracking whether one already ran. It also works with the feature
     * switch OFF: turning grants off must never strand live budget.
     */
    public function revokeAll(string $correlationId): int
    {
        if ($correlationId === '') {
            return 0;
        }

        $revoked = $this->store->revokeAll($correlationId);
        if ($revoked > 0) {
            $this->recorder->revoked($correlationId, $revoked);
        }

        return $revoked;
    }

    /** Read one grant's current state; null when the id is unknown. */
    public function find(string $grantId): ?Grant
    {
        return $this->store->get($grantId);
    }

    /**
     * Every grant issued in one scope, newest first — for a host's own reporting
     * ("what was this run allowed to do?"). Never consulted by the gate.
     *
     * @return list<Grant>
     */
    public function forCorrelation(string $correlationId): array
    {
        return $this->store->forCorrelation($correlationId);
    }
}
