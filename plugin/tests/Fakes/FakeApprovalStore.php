<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use Specflux\AgentSafety\Approval\ApprovalStore;
use Specflux\AgentSafety\Approval\ReserveOutcome;
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
    /** @var array<string, array{verb: string, args_hash: string, summary: string, correlation_id: string, audit_event_id: string, subject: ?string, status: string, fingerprint: ?string, fingerprint_kind: ?string, probe_args?: ?string}> */
    public array $rows = [];

    /** @var list<array{verb: string, args_hash: string, summary: string, correlation_id: string, audit_event_id: string, subject: ?string, fingerprint: ?string, fingerprint_kind: string, probe_args: ?string}> */
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
            'fingerprint' => null,
            'fingerprint_kind' => null,
        ];

        return $id;
    }

    /**
     * AS-6 test control knob: seed an approved, unexpired grant that carries a
     * `probe`-kind fingerprint, so a test can drive {@see reserve()}'s
     * staleness comparison directly.
     */
    public function seedApprovedWithFingerprint(string $verb, string $argsHash, ?string $subject, string $fingerprint): string
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
            'fingerprint' => $fingerprint,
            'fingerprint_kind' => 'probe',
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
        ?string $fingerprint = null,
        string $fingerprintKind = 'none',
        ?string $probeArgs = null,
    ): string {
        $this->requestCalls[] = [
            'verb' => $verb,
            'args_hash' => $argsHash,
            'summary' => $summary,
            'correlation_id' => $correlationId,
            'audit_event_id' => $auditEventId,
            'subject' => $subject,
            'fingerprint' => $fingerprint,
            'fingerprint_kind' => $fingerprintKind,
            'probe_args' => $probeArgs,
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
            'fingerprint' => $fingerprint,
            'fingerprint_kind' => $fingerprint !== null ? $fingerprintKind : null,
            'probe_args' => $fingerprint !== null && $fingerprintKind === 'probe' ? $probeArgs : null,
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
            'fingerprint' => null,
            'fingerprint_kind' => 'grant',
        ];

        return $id;
    }

    public function peekApproved(?string $token, string $verb, string $argsHash, ?string $subject): bool
    {
        return $this->find($token, $verb, $argsHash, $subject) !== null;
    }

    public function reserve(?string $token, string $verb, string $argsHash, ?string $subject, ?string $currentFingerprint = null): ReserveOutcome
    {
        $id = $this->find($token, $verb, $argsHash, $subject);
        if ($id === null) {
            // AS-7 §3.4 item 6: mirror WpdbApprovalStore::reserve()'s fallback
            // check for a row a site-binding mismatch already voided.
            $voided = $this->findByStatus($token, $verb, $argsHash, $subject, 'void_environment');

            return $voided !== null ? ReserveOutcome::voidEnvironment($voided) : ReserveOutcome::none();
        }

        $kind = $this->rows[$id]['fingerprint_kind'] ?? null;
        $stored = $this->rows[$id]['fingerprint'] ?? null;

        if ($kind === 'probe' && ($currentFingerprint === null || $stored === null || !hash_equals($stored, $currentFingerprint))) {
            $this->rows[$id]['status'] = 'stale';

            return ReserveOutcome::stale($id, $stored);
        }

        $this->rows[$id]['status'] = 'in_flight';

        return ReserveOutcome::claimed($id);
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

    /** Test control knob mirroring {@see \Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore::markStale()}. */
    public function markStale(string $approvalId): bool
    {
        if (($this->rows[$approvalId]['status'] ?? null) !== 'pending') {
            return false;
        }

        $this->rows[$approvalId]['status'] = 'stale';

        return true;
    }

    /** @return array<string, mixed>|null */
    public function get(string $approvalId): ?array
    {
        return $this->rows[$approvalId] ?? null;
    }

    /**
     * Matches by bearer $token when given (against the row id, standing in
     * for a minted token in this fake), else by $subject -- the same rule
     * {@see ApprovalStore::peekApproved()} documents.
     */
    private function find(?string $token, string $verb, string $argsHash, ?string $subject): ?string
    {
        return $this->findByStatus($token, $verb, $argsHash, $subject, 'approved');
    }

    private function findByStatus(?string $token, string $verb, string $argsHash, ?string $subject, string $status): ?string
    {
        foreach ($this->rows as $id => $row) {
            if ($row['status'] !== $status || $row['verb'] !== $verb || $row['args_hash'] !== $argsHash) {
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

    /** Test control knob: seed a row already voided by a site-binding mismatch (AS-7 §3.4 item 5). */
    public function seedVoidEnvironment(string $verb, string $argsHash, ?string $subject): string
    {
        $id = $this->mintId();
        $this->rows[$id] = [
            'verb' => $verb,
            'args_hash' => $argsHash,
            'summary' => '',
            'correlation_id' => '',
            'audit_event_id' => '',
            'subject' => $subject,
            'status' => 'void_environment',
            'fingerprint' => null,
            'fingerprint_kind' => null,
        ];

        return $id;
    }

    private function mintId(): string
    {
        return 'apr_fake_' . (++$this->sequence);
    }
}
