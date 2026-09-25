<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Self;

use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\SelfRateLimit;
use Specflux\AgentSafety\Plugin\Support\SummaryMarkup;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;

/**
 * AS-8 (§3.5): the actual `agent-safety/check-approval` poll — everything the
 * Verdict pipeline's exemption ({@see VerdictPipeline::judgeCheckApproval()})
 * deliberately does NOT do: authentication, scoping to the caller's own
 * Approval rows, this ability's own fixed rate limit, the output shape, and
 * the `approval.probe` audit rule (§3.5 items 2, 6, 7).
 *
 * Bucket for the rate limit and the scoping match alike:
 * {@see RequestContext::tokenId()} — the SAME principal an Approval's
 * `key_id` is bound to (see AS-7's principal fix), so "my own approval" means
 * exactly what requested it.
 */
final class CheckApprovalAbility
{
    /** Bucket used for the rate limit when no principal resolves at all. */
    private const ANONYMOUS = '(anonymous)';

    public function __construct(
        private readonly ?WpdbApprovalStore $approvals,
        private readonly ?AuditSink $sink,
        private readonly ?PauseSwitch $pause = null,
        private readonly SelfRateLimit $rateLimit = new SelfRateLimit(),
    ) {
    }

    /** The ability's own permission check: open to any resolved caller — scoping happens in {@see execute()}. */
    public function permissionCallback(): bool
    {
        return true;
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function execute($input): array
    {
        $approvalId = is_array($input) && is_string($input['approval_id'] ?? null) ? $input['approval_id'] : '';
        $principal = RequestContext::tokenId();
        $identity = $principal ?? self::ANONYMOUS;

        if (!$this->rateLimit->admit($identity)) {
            $this->auditProbe($approvalId, 'rate_limited', null);

            return [
                'status' => 'rate_limited',
                'retry_after' => $this->rateLimit->retryAfterSeconds(),
            ];
        }

        $row = $approvalId !== '' ? $this->approvals?->get($approvalId) : null;
        if ($row === null) {
            // Unknown id: matches no row at all. Per §3.5 item 7 this is NOT
            // audited (only a mismatch against a row that DOES exist, or a
            // rate-limit hit, is) — an existence oracle would leak which ids
            // are real.
            return $this->notFound();
        }

        $rowKeyId = is_string($row['key_id'] ?? null) ? $row['key_id'] : null;
        if ($principal === null || $rowKeyId !== $principal) {
            // A row exists but does not belong to this caller (including a
            // caller with no resolved principal at all, which can never match
            // anything — even a row whose own key_id is null, §3.5 item 2).
            $this->auditProbe($approvalId, 'principal_mismatch', $row);

            return $this->notFound();
        }

        return $this->body($approvalId, $row);
    }

    /** @return array{status: string} */
    private function notFound(): array
    {
        return ['status' => 'not_found'];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function body(string $approvalId, array $row): array
    {
        $status = $this->publicStatus($row);
        $paused = $this->pause?->isPaused() ?? false;

        $body = [
            'approval_id' => $approvalId,
            'verb' => (string) ($row['verb'] ?? ''),
            'summary' => SummaryMarkup::unwrap((string) ($row['summary'] ?? '')),
            'created_at' => is_string($row['created_ts'] ?? null) ? $row['created_ts'] : null,
            'pending_expires_at' => is_string($row['pending_expires_ts'] ?? null) ? $row['pending_expires_ts'] : null,
            'status' => $status,
            'next_action' => $this->nextAction($status, $this->reasonFor($status, $row), $paused),
            'resolved_via' => $this->grantId($row) !== null ? 'grant' : 'human',
        ];

        $reason = $this->reasonFor($status, $row);
        if ($reason !== null) {
            $body['reason'] = $reason;
        }

        if ($paused) {
            $body['paused'] = true;
        }

        return $body;
    }

    /**
     * Internal status -> public status (§3.5 item 4 table), with one
     * addition the table's raw internal statuses don't cover: a `pending`
     * row whose TTL has already lapsed reads as `expired` even before the
     * hourly sweep flips the row, so a poll never reports a request as still
     * `pending` after a human could no longer act on it.
     *
     * @param array<string, mixed> $row
     */
    private function publicStatus(array $row): string
    {
        $internal = (string) ($row['status'] ?? '');

        if ($internal === 'pending' && $this->pastPendingTtl($row)) {
            return 'expired';
        }

        return match ($internal) {
            'in_flight', 'consumed' => 'used',
            'stale', 'void_environment' => 'superseded',
            default => $internal,
        };
    }

    /** @param array<string, mixed> $row */
    private function pastPendingTtl(array $row): bool
    {
        $expires = $row['pending_expires_ts'] ?? null;

        return is_string($expires) && $expires !== '' && $expires <= gmdate('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function reasonFor(string $publicStatus, array $row): ?string
    {
        if ('superseded' !== $publicStatus) {
            return null;
        }

        return 'void_environment' === ($row['status'] ?? null) ? 'site_moved' : 'target_changed';
    }

    /** @param array<string, mixed> $row */
    private function grantId(array $row): ?string
    {
        $grantId = $row['grant_id'] ?? null;

        return is_string($grantId) && $grantId !== '' ? $grantId : null;
    }

    private function nextAction(string $status, ?string $reason, bool $paused): string
    {
        $message = match ($status) {
            'pending' => __('Waiting for a human. Check again in 30 seconds or more, and stop at pending_expires_at.', 'agent-safety'),
            'approved' => __('Retry the original call now, with exactly the same arguments.', 'agent-safety'),
            'rejected' => __('A human rejected this request. Don\'t retry it; tell the user.', 'agent-safety'),
            'expired' => __('Nobody reviewed this request in time. Ask the user before trying again.', 'agent-safety'),
            'used' => __('This approval has already been used. Another call needs a new approval.', 'agent-safety'),
            'superseded' => 'site_moved' === $reason
                ? __('The site\'s address changed. Retrying the call files a fresh request.', 'agent-safety')
                : __('The target changed after the request. Retrying the call files a fresh request.', 'agent-safety'),
            default => '',
        };

        if (!$paused) {
            return $message;
        }

        return __('Agent Safety is paused on this site, so retrying won\'t work until an administrator resumes it. ', 'agent-safety') . $message;
    }

    /** @param array<string, mixed>|null $row */
    private function auditProbe(string $approvalId, string $trigger, ?array $row): void
    {
        if ($this->sink === null) {
            return;
        }

        $this->sink->append(AuditRecord::decision(
            id: RequestContext::event(),
            ts: RequestContext::nowUtc(),
            correlationId: RequestContext::correlation(),
            pack: 'agent-safety-self',
            actor: RequestContext::actor(),
            ability: VerdictPipeline::CHECK_APPROVAL_VERB,
            tier: Tier::Reversible->value,
            input: ['approval_id' => $approvalId, 'trigger' => $trigger],
            decision: AuditDecision::Denied,
            approval: $row !== null ? ['id' => (string) ($row['approval_id'] ?? $approvalId), 'approver' => null] : null,
            ip: RequestContext::ip(),
            reason: 'approval.probe',
        ));
    }
}
