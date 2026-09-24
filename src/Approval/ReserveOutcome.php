<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Approval;

/**
 * The outcome of {@see ApprovalStore::reserve()}: claimed one exact
 * execution, found nothing to claim, or found an approved grant whose
 * target's state fingerprint no longer matches the one captured when the
 * approval was requested (AS-6) — the store marks that row `stale` as a
 * side effect of returning this, rather than claiming it.
 */
final class ReserveOutcome
{
    private function __construct(
        public readonly ?string $approvalId,
        public readonly bool $stale,
        public readonly ?string $staleApprovalId = null,
        public readonly ?string $staleFingerprint = null,
    ) {
    }

    public static function claimed(string $approvalId): self
    {
        return new self($approvalId, false);
    }

    public static function none(): self
    {
        return new self(null, false);
    }

    /** @param ?string $oldFingerprint The stale row's OWN stored fingerprint (may be null if it somehow had none). */
    public static function stale(string $staleApprovalId, ?string $oldFingerprint): self
    {
        return new self(null, true, $staleApprovalId, $oldFingerprint);
    }
}
