<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Api;

use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;

/**
 * The programmatic approvals API (backlog AS-10). Before this service, the
 * ONLY way to grant/deny a pending approval was the wp-admin form
 * ({@see \Specflux\AgentSafety\Plugin\Admin\PendingActionsPage}); consumers
 * that meet the human somewhere else — an inline "Approve" card in a chat UI,
 * a CLI, a future REST route — had no seam to call.
 *
 * THE invariant: every caller funnels through HERE, so a resolution is one
 * code path no matter its origin —
 *  1. capability gate (`manage_options`, overridable only WIDER-or-narrower
 *     via the `agent_safety_can_approve` filter),
 *  2. the store's conditional UPDATE (a row approved/rejected at most once),
 *  3. the same hash-chained audit reconciliation row the admin form writes,
 *  4. the `agent_safety_approval_resolved` action.
 *
 * The wp-admin page itself delegates here (it keeps nonce + flash-token UX on
 * top), which makes "same audit as admin-post" true by construction.
 */
final class Approvals
{
    public function __construct(
        private readonly WpdbApprovalStore $store,
        private readonly ?AuditSink $sink = null,
        private readonly ?PackResolver $packs = null,
    ) {
    }

    /**
     * Grant a pending request. Returns true when THIS call flipped the row to
     * `approved` (false: unauthorized, unknown id, not pending, or past its
     * pending TTL). The minted single-use token is intentionally NOT returned:
     * inline approvers re-drive the original call by reference; delegation
     * callers who need the token use {@see approveReturningToken()}.
     */
    public function approve(string $id, int $byUserId): bool
    {
        return $this->approveReturningToken($id, $byUserId) !== null;
    }

    /**
     * {@see approve()}, returning the minted single-use bearer token (shown
     * ONCE, never stored in the clear) so a delegating approver can hand the
     * action to a different actor. Null exactly when {@see approve()} is false.
     */
    public function approveReturningToken(string $id, int $byUserId): ?string
    {
        if (!$this->authorized($id, $byUserId)) {
            return null;
        }

        $token = $this->store->approve($id, $byUserId);
        if ($token !== null) {
            $this->reconcile($id, AuditDecision::Approved, $byUserId);
            do_action('agent_safety_approval_resolved', $id, 'approved', $byUserId);
        }

        return $token;
    }

    /** Refuse a pending request. True when THIS call flipped the row to `rejected`. */
    public function reject(string $id, int $byUserId): bool
    {
        if (!$this->authorized($id, $byUserId)) {
            return false;
        }

        if ($this->store->reject($id, $byUserId)) {
            $this->reconcile($id, AuditDecision::Rejected, $byUserId);
            do_action('agent_safety_approval_resolved', $id, 'rejected', $byUserId);

            return true;
        }

        return false;
    }

    /** Read one approval's current state; null when the id is unknown. */
    public function find(string $id): ?ApprovalSummary
    {
        if ($id === '') {
            return null;
        }

        $row = $this->store->get($id);

        return $row !== null ? ApprovalSummary::fromRow($row) : null;
    }

    /**
     * The capability gate shared by every mutation and read here. The filter
     * may narrow (e.g. require a second factor) but can NEVER widen a user
     * who lacks `manage_options` into an approver: a filtered false denies,
     * and a filtered value that isn't `true` denies too (fail closed).
     */
    private function authorized(string $id, int $byUserId): bool
    {
        /** @var mixed $filtered */
        $filtered = apply_filters(
            'agent_safety_can_approve',
            current_user_can('manage_options'),
            $id,
            $byUserId,
        );

        return $filtered === true;
    }

    /**
     * Append the `approved`/`rejected` reconciliation event tied to the
     * original request — byte-identical to what the wp-admin form wrote
     * before AS-10 (that form now delegates here instead of duplicating it).
     */
    private function reconcile(string $approvalId, AuditDecision $decision, int $approver): void
    {
        if ($this->sink === null) {
            return;
        }

        $record = $this->store->get($approvalId);
        if ($record === null) {
            return;
        }

        $pack = $this->packs?->resolve()->name ?? 'default-agent';

        $this->sink->append(AuditRecord::decision(
            id: RequestContext::event(),
            ts: RequestContext::nowUtc(),
            correlationId: (string) ($record['correlation_id'] ?? ''),
            pack: $pack,
            actor: RequestContext::actor(),
            ability: (string) ($record['verb'] ?? ''),
            tier: null,
            input: ['args_hash' => (string) ($record['args_hash'] ?? '')],
            decision: $decision,
            approval: ['id' => $approvalId, 'approver' => $approver],
            ip: RequestContext::ip(),
        ));
    }
}
