<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Verdict;

use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use WP_Error;

/**
 * What actually happened to one call after the {@see VerdictPipeline} ran: the
 * core {@see Decision} plus its consequences. Adapters translate a Verdict into
 * their transport's shape and nothing else; {@see error()} is the ONLY producer
 * of the WP_Error contract consumers such as SenroFlux depend on.
 */
final class Verdict
{
    /**
     * @param ?string $approvalId         Pending approval persisted for this call (approval-required only).
     * @param ?string $reservedApprovalId Grant reserved by THIS call in claim mode; the adapter must
     *                                    finalize it on execution success or roll it back. Null when
     *                                    nothing was reserved, including a re-entrant claim that
     *                                    reused this request's earlier reservation.
     * @param bool    $claimed            A human grant satisfied this call (fresh or re-entrant).
     * @param bool    $shadowed           The pack is in shadow mode: the decision would have blocked
     *                                    the call but it proceeds, audited as a dry run.
     * @param ?string $eventId            Audit event id the verdict was recorded under (non-executing
     *                                    and shadowed verdicts only; allowed calls are audited at
     *                                    execution time).
     */
    public function __construct(
        public readonly string $verb,
        public readonly Pack $pack,
        public readonly Decision $decision,
        public readonly ?string $approvalId = null,
        public readonly ?string $reservedApprovalId = null,
        public readonly bool $claimed = false,
        public readonly bool $shadowed = false,
        public readonly ?string $eventId = null,
    ) {
    }

    /** Should the call run? True for an allowed call and for a shadowed block. */
    public function proceeds(): bool
    {
        return $this->shadowed || $this->decision->isAllowed();
    }

    /**
     * The error a blocked call is reported with, or null when the call proceeds.
     *
     * Contract (frozen — see docs/adr/0001-single-verdict-pipeline.md and
     * plugin/tests/Fixtures/VerdictErrorFixture.php):
     *   - deny:              code `agent_safety_denied`, data {status: 403, verb, tier}
     *   - approval required: code `approval_required`, data {status: 202, verb, tier, approval_id}
     * On the approval-required error null-valued data keys are dropped, so `tier` and
     * `approval_id` may be absent; the deny error always carries all three keys.
     */
    public function error(): ?WP_Error
    {
        if ($this->proceeds()) {
            return null;
        }

        if (Outcome::Deny === $this->decision->outcome) {
            return new WP_Error(
                'agent_safety_denied',
                sprintf('Blocked by Agent Safety (%s): %s', $this->pack->name, $this->decision->reason),
                ['status' => 403, 'verb' => $this->verb, 'tier' => $this->decision->tier?->value]
            );
        }

        return new WP_Error(
            'approval_required',
            sprintf('"%s" is irreversible and requires human approval before it can run. A request has been logged for review.', $this->verb),
            array_filter([
                'status' => 202,
                'verb' => $this->verb,
                'tier' => $this->decision->tier?->value,
                'approval_id' => $this->approvalId,
            ], static fn ($v) => $v !== null)
        );
    }
}
