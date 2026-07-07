<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Policy\Tier;

/**
 * {@see DecisionRecorder} is the seam shared by both gate hooks (permission
 * callback + pre-tool-call), so its contract is what actually guarantees a
 * denial or pending approval is recorded IDENTICALLY no matter which seam
 * intercepted the call. This locks in: the sink/redaction wiring on
 * auditDecision(), the pass-through/no-op behaviour when either collaborator
 * is null (both must degrade safely, per the class docblock), the exact
 * ApprovalStore::request()/peekApproved() arguments it derives, and the pure
 * summarize() formatting used both for the approval screen and as an
 * auditDecision() input via the approval flow.
 */
final class DecisionRecorderTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::reset();
    }

    public function testDeniedDecisionIsAuditedWithPiiRedactedPerPack(): void
    {
        $sink = new InMemoryAuditSink();
        $recorder = new DecisionRecorder($sink, null);
        $pack = new Pack(name: 'support', allow: ['*']); // default pii: 'redacted'
        $decision = Decision::deny('denied_by_pack', Tier::Irreversible);

        $recorder->auditDecision('evt_1', 'orders/refund', ['id' => 1, 'email' => 'a@b.com'], $pack, $decision);

        $this->assertCount(1, $sink->records);
        $record = $sink->records[0]->toArray();
        $this->assertSame('denied', $record['decision']);
        $this->assertSame(1, $record['input']['id']);
        $this->assertSame('«redacted»', $record['input']['email']);
    }

    public function testFullPiiPackDoesNotRedact(): void
    {
        $sink = new InMemoryAuditSink();
        $recorder = new DecisionRecorder($sink, null);
        $pack = new Pack(name: 'owner', allow: ['*'], pii: 'full');
        $decision = Decision::deny('denied_by_pack', Tier::Irreversible);

        $recorder->auditDecision('evt_1', 'orders/refund', ['email' => 'a@b.com'], $pack, $decision);

        $this->assertSame('a@b.com', $sink->records[0]->toArray()['input']['email']);
    }

    public function testAuditDecisionIsANoOpWithoutASink(): void
    {
        // No sink to append to and an approvals store that auditDecision never
        // touches -- the call must simply not throw, and must leave the
        // unrelated collaborator untouched.
        $approvals = new FakeApprovalStore();
        $recorder = new DecisionRecorder(null, $approvals);

        $recorder->auditDecision('evt_1', 'orders/refund', ['id' => 1], new Pack('p', ['*']), Decision::deny('x'));

        $this->assertSame([], $approvals->requestCalls);
    }

    public function testRequestApprovalPersistsViaTheApprovalStoreAndReturnsItsId(): void
    {
        $store = new FakeApprovalStore();
        $store->nextId = 'apr_123';
        $recorder = new DecisionRecorder(null, $store);
        $input = ['id' => 42];

        $id = $recorder->requestApproval('orders/refund', $input, 'evt_1');

        $this->assertSame('apr_123', $id);
        $this->assertCount(1, $store->requestCalls);
        $call = $store->requestCalls[0];
        $this->assertSame('orders/refund', $call['verb']);
        $this->assertSame(ApprovalBinding::hash('orders/refund', $input), $call['args_hash']);
        $this->assertSame('orders/refund { id=42 }', $call['summary']);
        $this->assertSame(RequestContext::correlation(), $call['correlation_id']);
        $this->assertSame('evt_1', $call['audit_event_id']);
        $this->assertNull($call['subject']);
    }

    public function testRequestApprovalReturnsNullWithoutAnApprovalStore(): void
    {
        $recorder = new DecisionRecorder(null, null);

        $this->assertNull($recorder->requestApproval('orders/refund', ['id' => 1], 'evt_1'));
    }

    public function testHasApprovedGrantIsTrueForAMatchingBySubjectGrant(): void
    {
        $verb = 'orders/refund';
        $input = ['id' => 1];
        $hash = ApprovalBinding::hash($verb, $input);
        $store = new FakeApprovalStore();
        // RequestContext is never configured in this test, so tokenId() is
        // null -- seed the grant against that same null subject.
        $store->seedApproved($verb, $hash, null);
        $recorder = new DecisionRecorder(null, $store);

        $this->assertTrue($recorder->hasApprovedGrant($verb, $input));
    }

    public function testHasApprovedGrantIsFalseWhenNothingMatches(): void
    {
        $store = new FakeApprovalStore();
        $recorder = new DecisionRecorder(null, $store);

        $this->assertFalse($recorder->hasApprovedGrant('orders/refund', ['id' => 1]));
    }

    public function testHasApprovedGrantMatchesByBearerTokenOverSubject(): void
    {
        $verb = 'orders/refund';
        $input = ['id' => 1];
        $hash = ApprovalBinding::hash($verb, $input);
        $store = new FakeApprovalStore();
        // Seed with a DIFFERENT subject than the caller's (null, since
        // RequestContext isn't configured) -- only the bearer-token match can
        // find this row, proving the token path (not the subject path) is
        // what's exercised when the input carries the `_approval` arg.
        $approvalId = $store->seedApproved($verb, $hash, 'some-other-subject');
        $inputWithToken = $input + [ApprovalBinding::TOKEN_ARG => $approvalId];
        $recorder = new DecisionRecorder(null, $store);

        $this->assertTrue($recorder->hasApprovedGrant($verb, $inputWithToken));
    }

    public function testHasApprovedGrantReturnsFalseWithoutAnApprovalStore(): void
    {
        $recorder = new DecisionRecorder(null, null);

        $this->assertFalse($recorder->hasApprovedGrant('orders/refund', ['id' => 1]));
    }

    public function testApprovalPendingPathLinksTheAuditRecordToTheReturnedApprovalId(): void
    {
        $sink = new InMemoryAuditSink();
        $store = new FakeApprovalStore();
        $store->nextId = 'apr_999';
        $recorder = new DecisionRecorder($sink, $store);
        $verb = 'orders/refund';
        $input = ['id' => 7];
        $decision = Decision::approvalRequired(Tier::Irreversible);

        $approvalId = $recorder->requestApproval($verb, $input, 'evt_42');
        $recorder->auditDecision('evt_42', $verb, $input, new Pack('p', ['*']), $decision, $approvalId);

        $this->assertSame('apr_999', $approvalId);
        $record = $sink->records[0]->toArray();
        $this->assertSame('pending', $record['decision']);
        $this->assertSame(['id' => 'apr_999', 'approver' => null], $record['approval']);
    }

    public function testSummarizeFormatsScalarArgsAndSkipsTheApprovalToken(): void
    {
        $recorder = new DecisionRecorder(null, null);
        $input = ['a' => 1, 'b' => 2, ApprovalBinding::TOKEN_ARG => 'tok', 'nested' => [1, 2, 3]];

        $summary = $recorder->summarize('orders/refund', $input);

        $this->assertSame('orders/refund { a=1, b=2 }', $summary);
    }

    public function testSummarizeWithNoDisplayableArgsIsJustTheVerb(): void
    {
        $recorder = new DecisionRecorder(null, null);

        $this->assertSame('orders/refund', $recorder->summarize('orders/refund', [ApprovalBinding::TOKEN_ARG => 'tok']));
    }

    public function testSummarizeCapsAtEightParts(): void
    {
        $recorder = new DecisionRecorder(null, null);
        $input = [];
        for ($i = 1; $i <= 10; $i++) {
            $input["k{$i}"] = $i;
        }

        $summary = $recorder->summarize('orders/bulk', $input);

        $expectedParts = [];
        for ($i = 1; $i <= 8; $i++) {
            $expectedParts[] = "k{$i}={$i}";
        }
        $this->assertSame('orders/bulk { ' . implode(', ', $expectedParts) . ' }', $summary);
    }
}
