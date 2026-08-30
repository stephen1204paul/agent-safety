<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use Specflux\AgentSafety\Approval\ApprovalStore;
use Specflux\AgentSafety\Plugin\Approval\ApprovalMinter;

/**
 * Hand-rolled in-memory fake (house style: no mocking framework, see
 * tests/Fakes/InMemoryAuditSink.php). Rows are plain arrays keyed by
 * approval id, shaped close enough to {@see ApprovalStore}'s documented
 * record shape to drive status transitions; unlike WpdbApprovalStore this
 * has no real atomicity, just enough bookkeeping to make DecisionRecorder's
 * two call sites (request(), peekApproved()) observable and controllable
 * from a test.
 */
final class FakeApprovalStore implements ApprovalStore, ApprovalMinter
{
    /** @var array<string, array{verb: string, args_hash: string, summary: string, correlation_id: string, audit_event_id: string, subject: ?string, status: string}> */
    public array $rows = [];

    /** @var list<array{verb: string, args_hash: string, summary: string, correlation_id: string, audit_event_id: string, subject: ?string}> */
    public array $requestCalls = [];

    /** @var list<array{verb: string, args_hash: string, summary: string, subject: ?string, approver: ?int, grant_id: ?string}> */
    public array $mintCalls = [];

    /** Test control knob: make mintApproved() report a failed write. */
    public bool $mintFails = false;

    /** Test control knob: the id request() returns next; auto-generated when null. */
    public ?string $nextId = null;

    private int $sequence = 0;

    /** Test control knob: seed an approved, unexpired grant directly. */
    public function seedApproved(string $verb, string $argsHash, ?string $subject): string
    {
        $id = $this->mintId();
        $this->rows[$id] = [
            'verb' => $verb,
            'args_hash' => $argsHash,
            'summary' => '',
            'correlation_id' => '',
            'audit_event_id' => '',
            'subject' => $subject,
            'status' => 'approved',
        ];

        return $id;
    }

    public function request(
        string $verb,
        string $argsHash,
        string $summary,
        string $correlationId,
        string $auditEventId,
        ?string $subject,
    ): string {
        $this->requestCalls[] = [
            'verb' => $verb,
            'args_hash' => $argsHash,
            'summary' => $summary,
            'correlation_id' => $correlationId,
            'audit_event_id' => $auditEventId,
            'subject' => $subject,
        ];

        $id = $this->nextId ?? $this->mintId();
        $this->rows[$id] = [
            'verb' => $verb,
            'args_hash' => $argsHash,
            'summary' => $summary,
            'correlation_id' => $correlationId,
            'audit_event_id' => $auditEventId,
            'subject' => $subject,
            'status' => 'pending',
        ];

        return $id;
    }

    /**
     * {@see ApprovalMinter::mintApproved()} -- an ALREADY-approved row, as a
     * pre-approval grant writes it. Deliberately no token: a minted record is
     * claimable by-reference only.
     */
    public function mintApproved(
        string $verb,
        string $argsHash,
        string $summary,
        string $correlationId,
        string $auditEventId,
        ?string $subject,
        ?int $approver,
        ?string $grantId,
    ): ?string {
        $this->mintCalls[] = [
            'verb' => $verb,
            'args_hash' => $argsHash,
            'summary' => $summary,
            'subject' => $subject,
            'approver' => $approver,
            'grant_id' => $grantId,
        ];

        if ($this->mintFails) {
            return null;
        }

        $id = $this->mintId();
        $this->rows[$id] = [
            'verb' => $verb,
            'args_hash' => $argsHash,
            'summary' => $summary,
            'correlation_id' => $correlationId,
            'audit_event_id' => $auditEventId,
            'subject' => $subject,
            'status' => 'approved',
        ];

        return $id;
    }

    public function peekApproved(?string $token, string $verb, string $argsHash, ?string $subject): bool
    {
        return $this->find($token, $verb, $argsHash, $subject) !== null;
    }

    public function reserve(?string $token, string $verb, string $argsHash, ?string $subject): ?string
    {
        $id = $this->find($token, $verb, $argsHash, $subject);
        if ($id === null) {
            return null;
        }

        $this->rows[$id]['status'] = 'in_flight';

        return $id;
    }

    public function finalize(string $approvalId): void
    {
        if (($this->rows[$approvalId]['status'] ?? null) === 'in_flight') {
            $this->rows[$approvalId]['status'] = 'consumed';
        }
    }

    public function rollback(string $approvalId): void
    {
        if (($this->rows[$approvalId]['status'] ?? null) === 'in_flight') {
            $this->rows[$approvalId]['status'] = 'approved';
        }
    }

    /**
     * Matches by bearer $token when given (against the row id, standing in
     * for a minted token in this fake), else by $subject -- the same rule
     * {@see ApprovalStore::peekApproved()} documents.
     */
    private function find(?string $token, string $verb, string $argsHash, ?string $subject): ?string
    {
        foreach ($this->rows as $id => $row) {
            if ($row['status'] !== 'approved' || $row['verb'] !== $verb || $row['args_hash'] !== $argsHash) {
                continue;
            }

            if ($token !== null) {
                if ($id === $token) {
                    return $id;
                }
                continue;
            }

            if ($row['subject'] === $subject) {
                return $id;
            }
        }

        return null;
    }

    private function mintId(): string
    {
        return 'apr_fake_' . (++$this->sequence);
    }
}
