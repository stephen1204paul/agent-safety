<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use Specflux\AgentSafety\Approval\Grant;
use Specflux\AgentSafety\Approval\GrantStatus;
use Specflux\AgentSafety\Approval\GrantStore;

/**
 * Hand-rolled in-memory {@see GrantStore} (house style: no mocking framework).
 *
 * It delegates the MATCHING RULE to the real {@see Grant::canReserve()} rather
 * than reimplementing it, so a test driving this fake exercises the same
 * fail-closed logic {@see \Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore}
 * consults. What it does NOT model is atomicity — that guarantee lives in the
 * wpdb store's conditional UPDATE and is asserted on the SQL there.
 */
final class FakeGrantStore implements GrantStore
{
    /** @var array<string, Grant> */
    public array $grants = [];

    /** Test control knob: freeze "now" for TTL comparisons. */
    public string $now;

    /** Test control knob: make the next mintable read fail (a torn write). */
    public bool $getReturnsNull = false;

    private int $sequence = 0;

    public function __construct(?string $now = null)
    {
        $this->now = $now ?? gmdate('Y-m-d H:i:s');
    }

    public function issue(
        string $verb,
        int $count,
        ?string $subject,
        string $correlationId,
        ?int $grantedBy,
        ?string $planStepId,
    ): string {
        $grantId = 'gnt_fake_' . (++$this->sequence);
        $this->grants[$grantId] = new Grant(
            grantId: $grantId,
            correlationId: $correlationId,
            verb: $verb,
            remainingCount: max(0, $count),
            subject: $subject,
            grantedBy: $grantedBy,
            planStepId: $planStepId,
            createdTs: $this->now,
            expiresTs: gmdate('Y-m-d H:i:s', strtotime($this->now . ' UTC') + 86400),
            revokedTs: null,
            status: GrantStatus::Active,
        );

        return $grantId;
    }

    public function reserve(string $correlationId, string $verb, ?string $subject): ?string
    {
        foreach ($this->grants as $id => $grant) {
            if (!$grant->canReserve($correlationId, $verb, $subject, $this->now)) {
                continue;
            }

            $remaining = $grant->remainingCount - 1;
            $this->grants[$id] = $this->with(
                $grant,
                $remaining,
                $remaining < 1 ? GrantStatus::Exhausted : GrantStatus::Active,
            );

            return $id;
        }

        return null;
    }

    public function release(string $grantId): void
    {
        $grant = $this->grants[$grantId] ?? null;
        if ($grant === null || $grant->revokedTs !== null || $grant->status === GrantStatus::Revoked) {
            return;
        }

        $this->grants[$grantId] = $this->with($grant, $grant->remainingCount + 1, GrantStatus::Active);
    }

    public function revokeByCorrelation(string $correlationId): void
    {
        foreach ($this->grants as $id => $grant) {
            if ($grant->correlationId !== $correlationId || $grant->status === GrantStatus::Revoked) {
                continue;
            }

            $this->grants[$id] = new Grant(
                grantId: $grant->grantId,
                correlationId: $grant->correlationId,
                verb: $grant->verb,
                remainingCount: $grant->remainingCount,
                subject: $grant->subject,
                grantedBy: $grant->grantedBy,
                planStepId: $grant->planStepId,
                createdTs: $grant->createdTs,
                expiresTs: $grant->expiresTs,
                revokedTs: $this->now,
                status: GrantStatus::Revoked,
            );
        }
    }

    public function get(string $grantId): ?Grant
    {
        if ($this->getReturnsNull) {
            return null;
        }

        return $this->grants[$grantId] ?? null;
    }

    /** Test helper: overwrite one stored grant wholesale (expiry, revocation, …). */
    public function put(Grant $grant): void
    {
        $this->grants[$grant->grantId] = $grant;
    }

    private function with(Grant $grant, int $remaining, GrantStatus $status): Grant
    {
        return new Grant(
            grantId: $grant->grantId,
            correlationId: $grant->correlationId,
            verb: $grant->verb,
            remainingCount: $remaining,
            subject: $grant->subject,
            grantedBy: $grant->grantedBy,
            planStepId: $grant->planStepId,
            createdTs: $grant->createdTs,
            expiresTs: $grant->expiresTs,
            revokedTs: $grant->revokedTs,
            status: $status,
        );
    }
}
