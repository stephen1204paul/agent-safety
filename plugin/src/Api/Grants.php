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
 * server-side code that already has a human's decision in hand — and that
 * decision is checked here, not taken on trust. Every grant names its grantor,
 * who must hold `manage_options` (or be vouched for by the
 * `agent_safety_can_grant` filter, honoured only when it returns literal
 * `true`), and asks for no more than `agent_safety_grant_max_count` calls. A
 * cron tick or WP-CLI run with no human behind it therefore cannot issue one;
 * a host carries the accepting user's id from the request that captured the
 * decision. Every refusal past the feature switch is audited
 * (`grant.refused`), so a host asking for more than the site allows shows up
 * in the trail instead of quietly getting nothing. The feature switch itself
 * is the site-level consent that any of this happens at all.
 */
final class Grants
{
    /** The most calls one grant may carry unless `agent_safety_grant_max_count` says otherwise. */
    public const MAX_COUNT = 50;

    public const REFUSED_INVALID_REQUEST = 'invalid_request';
    public const REFUSED_NO_GRANTOR = 'no_grantor';
    public const REFUSED_NOT_AUTHORIZED = 'not_authorized';
    public const REFUSED_COUNT_ABOVE_MAX = 'count_above_max';

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
     * Refuses (null, nothing written) when the feature is off, the count is not
     * positive, the subject is empty — a grant with no principal is a grant to
     * anyone — the scope is empty, no grantor is named, the grantor is not
     * authorised, or the count is above the site's ceiling. Every refusal but
     * the feature switch writes a `grant.refused` audit event carrying why. The
     * caller is expected to treat null as "the human's decision did not take
     * effect", not as "it probably worked".
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
            $this->refuse(self::REFUSED_INVALID_REQUEST, $verb, $count, $correlationId, $grantedBy);

            return null;
        }

        if ($grantedBy === null || $grantedBy < 1) {
            $this->refuse(self::REFUSED_NO_GRANTOR, $verb, $count, $correlationId, $grantedBy);

            return null;
        }

        if (!$this->authorized($grantedBy, $verb, $count, $correlationId)) {
            $this->refuse(self::REFUSED_NOT_AUTHORIZED, $verb, $count, $correlationId, $grantedBy);

            return null;
        }

        if ($count > $this->maxCount()) {
            $this->refuse(self::REFUSED_COUNT_ABOVE_MAX, $verb, $count, $correlationId, $grantedBy);

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

    /**
     * The grantor gate, the same shape as {@see Approvals::authorized()}. The
     * default asks whether the GRANTOR holds `manage_options` — the named user,
     * not the current one, since the human who accepted need not be on the
     * request that issues — and `agent_safety_can_grant` may override it either
     * way. Only a literal `true` issues: a filtered false denies, and so does any
     * other value (fail closed), so a host vouching for a grantor has to say so
     * exactly.
     */
    private function authorized(int $grantedBy, string $verb, int $count, string $correlationId): bool
    {
        /** @var mixed $filtered */
        $filtered = apply_filters(
            'agent_safety_can_grant',
            user_can($grantedBy, 'manage_options'),
            $grantedBy,
            $verb,
            $count,
            $correlationId,
        );

        return $filtered === true;
    }

    /**
     * The most calls one grant may carry (`agent_safety_grant_max_count`). A
     * filtered value that is not a positive int is ignored. A request above the
     * ceiling is refused rather than clamped: a host asking for more than the
     * site allows is a configuration error, and issuing less would hide it.
     */
    private function maxCount(): int
    {
        /** @var mixed $filtered */
        $filtered = apply_filters('agent_safety_grant_max_count', self::MAX_COUNT);

        return is_int($filtered) && $filtered > 0 ? $filtered : self::MAX_COUNT;
    }

    private function refuse(string $reason, string $verb, int $count, string $correlationId, ?int $grantedBy): void
    {
        $this->recorder->refused($verb, $count, $correlationId, $grantedBy, $reason);
    }
}
