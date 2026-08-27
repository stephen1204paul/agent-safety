<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Support\ValueAccumulator;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use Specflux\AgentSafety\Plugin\Verdict\VerdictMode;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * The BEHAVIOUR suite for the governed-call decision: rate caps, argument
 * caps, shadow mode, the approval lifecycle, re-entrancy and the
 * annotation-hint rules, all driven through {@see VerdictPipeline::judge()}
 * and asserted on the returned {@see \Specflux\AgentSafety\Plugin\Verdict\Verdict}.
 *
 * These used to be written twice — once per gate seam — because each seam
 * owned its own copy of the evaluation. Now that both seams are thin adapters
 * of this one pipeline (docs/adr/0001-single-verdict-pipeline.md), the
 * behaviour is proven ONCE here, and the seam tests
 * ({@see \Specflux\AgentSafety\Plugin\Tests\Hooks\AbilityPermissionGateTest},
 * {@see \Specflux\AgentSafety\Plugin\Tests\Hooks\PreToolCallGateTest}) only
 * prove their own translation layer. Anything that differs between the seams
 * is a {@see VerdictMode} difference, so it is covered here in both modes
 * rather than in two files.
 *
 * The pipeline is handed its Pack directly (PackResolver is an adapter
 * concern), so the only host state these tests wire is the identity chain the
 * rate/quota buckets and the audit actor are keyed by.
 */
final class VerdictPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        // Hand the clock back rather than unsetting it: stubs/wpas-clock.php
        // seeds this global once at bootstrap and reads it unguarded, so a test
        // that freezes time must restore a value, not remove one.
        $GLOBALS['wpas_test_time'] = \time();
    }

    /**
     * Both modes, for the rules that must hold identically on either seam.
     *
     * @return array<string, array{VerdictMode}>
     */
    public static function modes(): array
    {
        return [
            'peek (mcp_adapter_pre_tool_call)' => [VerdictMode::Peek],
            'claim (permission_callback)' => [VerdictMode::Claim],
        ];
    }

    /**
     * One pipeline wired over a catalog of test verbs and the identity the
     * per-token buckets key on. A single instance per test on purpose: the
     * re-entrancy memo and the gate memos live on the instance, exactly as
     * they do for the one shared instance a real request uses.
     *
     * @param array<string, Tier> $verbToTier
     */
    private function pipeline(
        array $verbToTier,
        ?DecisionRecorder $recorder = null,
        ?FakeApprovalStore $approvals = null,
    ): VerdictPipeline {
        $catalog = new VerbCatalog();
        $catalog->register($verbToTier);

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));

        return new VerdictPipeline(
            new Gate(new TierClassifier($catalog)),
            $recorder ?? new DecisionRecorder(),
            $approvals,
        );
    }

    /** A pack that allows the demo verbs outright but gates tier 2 on approval. */
    private function approvalGatedPack(): Pack
    {
        return new Pack(name: 'support', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
    }

    // --- Rate / quota caps ---------------------------------------------------

    public function testRateLimitAllowsCallsUnderThePackCap(): void
    {
        $GLOBALS['wpas_test_time'] = 1_700_000_000;
        $pack = new Pack(name: 'capped', allow: ['demo/*'], limits: ['calls_per_minute' => 2]);
        $pipeline = $this->pipeline(['demo/read' => Tier::Reversible]);

        // Distinct args on purpose: identical (verb, args) within one request is
        // memoized as the host re-checking the SAME call, not a new call.
        $this->assertTrue($pipeline->judge('demo/read', ['call' => 1], $pack, Hints::none(), VerdictMode::Claim)->proceeds());
        $this->assertTrue($pipeline->judge('demo/read', ['call' => 2], $pack, Hints::none(), VerdictMode::Claim)->proceeds());
    }

    public function testRateLimitDeniesBeyondThePackCapAndADenialNeverConsumesQuota(): void
    {
        $GLOBALS['wpas_test_time'] = 1_700_000_000;
        $pack = new Pack(name: 'capped', allow: ['demo/*'], limits: ['calls_per_minute' => 1]);
        $pipeline = $this->pipeline(['demo/read' => Tier::Reversible]);

        $this->assertTrue($pipeline->judge('demo/read', ['call' => 1], $pack, Hints::none(), VerdictMode::Claim)->proceeds());

        $second = $pipeline->judge('demo/read', ['call' => 2], $pack, Hints::none(), VerdictMode::Claim);
        $this->assertFalse($second->proceeds());
        $this->assertSame(Outcome::Deny, $second->decision->outcome);
        $this->assertSame('rate_limited_calls_per_minute', $second->decision->reason);

        // The denial must not itself have spent (or freed) the single slot: a
        // third genuinely-new call is still blocked, and still by the cap.
        $third = $pipeline->judge('demo/read', ['call' => 3], $pack, Hints::none(), VerdictMode::Claim);
        $this->assertSame('rate_limited_calls_per_minute', $third->decision->reason);
    }

    public function testUnlimitedPackIsNeverRateLimited(): void
    {
        $GLOBALS['wpas_test_time'] = 1_700_000_000;
        $pack = new Pack(name: 'unlimited', allow: ['demo/*']);
        $pipeline = $this->pipeline(['demo/read' => Tier::Reversible]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($pipeline->judge('demo/read', ['call' => $i], $pack, Hints::none(), VerdictMode::Claim)->proceeds());
        }
    }

    // --- Argument-aware caps -------------------------------------------------

    public function testArgumentCapOverMaxPerCallDeniesAndAuditsExactlyOneRecord(): void
    {
        $cap = new ArgumentCap('refund_total', 'demo/*', 'amount', maxPerCall: 100.0);
        $pack = new Pack(name: 'capped-args', allow: ['demo/*'], argumentCaps: [$cap]);
        $sink = new InMemoryAuditSink();
        $pipeline = $this->pipeline(['demo/refund' => Tier::Reversible], new DecisionRecorder($sink));

        $verdict = $pipeline->judge('demo/refund', ['amount' => 500], $pack, Hints::none(), VerdictMode::Claim);

        $this->assertFalse($verdict->proceeds());
        $this->assertSame('argument_cap_refund_total_max_per_call', $verdict->decision->reason);
        $this->assertNotNull($verdict->eventId);
        $this->assertCount(1, $sink->records);
        $this->assertSame('denied', $sink->records[0]->toArray()['decision']);
    }

    public function testArgumentCapApprovalAboveWithNoGrantParksAndPersistsAPendingApprovalInClaimMode(): void
    {
        $cap = new ArgumentCap('big_edit', 'demo/*', 'amount', approvalAbove: 100.0);
        $pack = new Pack(name: 'approval-args', allow: ['demo/*'], argumentCaps: [$cap]);
        $approvals = new FakeApprovalStore();
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Reversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $verdict = $pipeline->judge('demo/refund', ['amount' => 500], $pack, Hints::none(), VerdictMode::Claim);

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertNotNull($verdict->approvalId, 'a parked call must leave a pending request for a human to review');
        $this->assertCount(1, $approvals->requestCalls);
        $this->assertSame('demo/refund', $approvals->requestCalls[0]['verb']);
    }

    public function testArgumentCapApprovalAboveWithNoGrantParksWithoutReservingInPeekMode(): void
    {
        $cap = new ArgumentCap('big_edit', 'demo/*', 'amount', approvalAbove: 100.0);
        $pack = new Pack(name: 'approval-args', allow: ['demo/*'], argumentCaps: [$cap]);
        $approvals = new FakeApprovalStore();
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Reversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $verdict = $pipeline->judge('demo/refund', ['amount' => 500], $pack, Hints::none(), VerdictMode::Peek);

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertNotNull($verdict->approvalId);
        // Peek mode never mutates the store: only the claiming seam reserves.
        $this->assertNull($verdict->reservedApprovalId);
        $this->assertFalse($verdict->claimed);
    }

    public function testArgumentCapApprovalAboveWithASeededGrantIsClaimedAndAccumulatesInClaimMode(): void
    {
        $cap = new ArgumentCap('big_edit', 'demo/*', 'amount', approvalAbove: 100.0, maxTotalPerDay: 1000.0);
        $pack = new Pack(name: 'approval-args', allow: ['demo/*'], argumentCaps: [$cap]);
        $approvals = new FakeApprovalStore();
        $args = ['amount' => 500];
        $id = $approvals->seedApproved('demo/refund', ApprovalBinding::hash('demo/refund', $args), 'test:token');
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Reversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $verdict = $pipeline->judge('demo/refund', $args, $pack, Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds());
        $this->assertTrue($verdict->claimed);
        $this->assertSame($id, $verdict->reservedApprovalId, 'the adapter needs the id back to finalize/roll back');
        $this->assertSame('in_flight', $approvals->rows[$id]['status']);
        // The admitted call spends against the day budget; a parked one never would.
        $this->assertSame(
            ['big_edit' => 500.0],
            (new ValueAccumulator())->totalsFor('approval-args', 'test:token', ['big_edit']),
        );
    }

    public function testArgumentCapApprovalAboveWithASeededGrantProceedsWithoutReservingInPeekMode(): void
    {
        $cap = new ArgumentCap('big_edit', 'demo/*', 'amount', approvalAbove: 100.0, maxTotalPerDay: 1000.0);
        $pack = new Pack(name: 'approval-args', allow: ['demo/*'], argumentCaps: [$cap]);
        $approvals = new FakeApprovalStore();
        $args = ['amount' => 500];
        $id = $approvals->seedApproved('demo/refund', ApprovalBinding::hash('demo/refund', $args), 'test:token');
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Reversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $verdict = $pipeline->judge('demo/refund', $args, $pack, Hints::none(), VerdictMode::Peek);

        $this->assertTrue($verdict->proceeds());
        $this->assertNull($verdict->reservedApprovalId);
        // Still claimable by the permission_callback that runs right after.
        $this->assertSame('approved', $approvals->rows[$id]['status']);
    }

    public function testArgumentCapMaxTotalPerDayDeniesTheCallThatWouldCrossIt(): void
    {
        $cap = new ArgumentCap('refund_total', 'demo/*', 'amount', maxTotalPerDay: 150.0);
        $pack = new Pack(name: 'daily-cap', allow: ['demo/*'], argumentCaps: [$cap]);
        $pipeline = $this->pipeline(['demo/refund' => Tier::Reversible]);

        // Distinct args per call -- each is a genuinely new call, not a re-check.
        $this->assertTrue($pipeline->judge('demo/refund', ['amount' => 100, 'id' => 1], $pack, Hints::none(), VerdictMode::Claim)->proceeds());
        $this->assertTrue($pipeline->judge('demo/refund', ['amount' => 40, 'id' => 2], $pack, Hints::none(), VerdictMode::Claim)->proceeds());

        $third = $pipeline->judge('demo/refund', ['amount' => 20, 'id' => 3], $pack, Hints::none(), VerdictMode::Claim); // 160 > 150
        $this->assertFalse($third->proceeds());
        $this->assertSame('argument_cap_refund_total_max_total_per_day', $third->decision->reason);
    }

    // --- Shadow mode ---------------------------------------------------------

    public function testShadowedPackAuditsTheWouldBeDenialAsADryRunAndProceeds(): void
    {
        $pack = new Pack(name: 'observed', allow: []); // every verb would be not_in_pack
        $sink = new InMemoryAuditSink();
        $approvals = new FakeApprovalStore();
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['observed'];
        $pipeline = $this->pipeline(['demo/refund' => Tier::Reversible], new DecisionRecorder($sink, $approvals), $approvals);

        $verdict = $pipeline->judge('demo/refund', ['amount' => 5], $pack, Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds());
        $this->assertTrue($verdict->shadowed);
        $this->assertNull($verdict->error(), 'an observed pack must never block the call');
        $this->assertSame(Outcome::Deny, $verdict->decision->outcome, 'the would-be verdict is still recorded as a denial');
        $this->assertCount(1, $sink->records);
        $record = $sink->records[0]->toArray();
        $this->assertSame('denied', $record['decision']);
        $this->assertTrue($record['dry_run']);
    }

    public function testShadowedPackDoesNotPersistAPendingApprovalForAWouldBePark(): void
    {
        $pack = new Pack(name: 'observed', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
        $sink = new InMemoryAuditSink();
        $approvals = new FakeApprovalStore();
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['observed'];
        $pipeline = $this->pipeline(['demo/refund' => Tier::Irreversible], new DecisionRecorder($sink, $approvals), $approvals);

        $verdict = $pipeline->judge('demo/refund', ['amount' => 5], $pack, Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds());
        // The action already ran; asking a human to approve it after the fact
        // would be a lie, so no pending row is minted.
        $this->assertSame([], $approvals->requestCalls);
        $this->assertNull($verdict->approvalId);
        $record = $sink->records[0]->toArray();
        $this->assertSame('pending', $record['decision']);
        $this->assertTrue($record['dry_run']);
    }

    public function testANonShadowedPackStillEnforcesWhileAnotherPackIsShadowed(): void
    {
        $pack = new Pack(name: 'enforced', allow: []);
        $sink = new InMemoryAuditSink();
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['some-other-pack'];
        $pipeline = $this->pipeline(['demo/refund' => Tier::Reversible], new DecisionRecorder($sink));

        $verdict = $pipeline->judge('demo/refund', ['amount' => 5], $pack, Hints::none(), VerdictMode::Claim);

        $this->assertFalse($verdict->proceeds());
        $this->assertFalse($verdict->shadowed);
        $this->assertFalse($sink->records[0]->toArray()['dry_run']);
    }

    // --- Approval flow -------------------------------------------------------

    public function testIrreversibleVerbWithNoGrantParksAndAuditsExactlyOnce(): void
    {
        $sink = new InMemoryAuditSink();
        $approvals = new FakeApprovalStore();
        $pipeline = $this->pipeline(['demo/refund' => Tier::Irreversible], new DecisionRecorder($sink, $approvals), $approvals);

        $verdict = $pipeline->judge('demo/refund', ['amount' => 5], $this->approvalGatedPack(), Hints::none(), VerdictMode::Claim);

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertSame(Tier::Irreversible, $verdict->decision->tier);
        $this->assertNotNull($verdict->approvalId);
        $this->assertNull($verdict->reservedApprovalId, 'nothing to reserve until a human grants it');
        $this->assertCount(1, $sink->records);
        $this->assertSame('pending', $sink->records[0]->toArray()['decision']);
    }

    public function testIrreversibleVerbWithASeededGrantIsClaimedInClaimMode(): void
    {
        $approvals = new FakeApprovalStore();
        $args = ['amount' => 5];
        $id = $approvals->seedApproved('demo/refund', ApprovalBinding::hash('demo/refund', $args), 'test:token');
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Irreversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $verdict = $pipeline->judge('demo/refund', $args, $this->approvalGatedPack(), Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds());
        $this->assertTrue($verdict->claimed);
        $this->assertSame($id, $verdict->reservedApprovalId);
        $this->assertSame('in_flight', $approvals->rows[$id]['status']);
    }

    public function testIrreversibleVerbWithASeededGrantProceedsUnclaimedInPeekMode(): void
    {
        $approvals = new FakeApprovalStore();
        $args = ['amount' => 5];
        $id = $approvals->seedApproved('demo/refund', ApprovalBinding::hash('demo/refund', $args), 'test:token');
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Irreversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $verdict = $pipeline->judge('demo/refund', $args, $this->approvalGatedPack(), Hints::none(), VerdictMode::Peek);

        $this->assertTrue($verdict->proceeds());
        $this->assertFalse($verdict->claimed, 'the peeking seam admits the retry but leaves the grant for the claiming seam');
        $this->assertNull($verdict->reservedApprovalId);
        $this->assertSame('approved', $approvals->rows[$id]['status']);
    }

    // --- Re-entrancy ---------------------------------------------------------

    public function testRepeatedClaimModeJudgementsReserveTheGrantOnlyOnce(): void
    {
        // Woo runs an allowed ability through rest_do_request, which re-enters
        // the permission check within the SAME request while the grant is
        // already in_flight -- a second reservation there would be a second
        // approval spent on one action.
        $approvals = new FakeApprovalStore();
        $args = ['amount' => 5];
        $id = $approvals->seedApproved('demo/refund', ApprovalBinding::hash('demo/refund', $args), 'test:token');
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Irreversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $first = $pipeline->judge('demo/refund', $args, $this->approvalGatedPack(), Hints::none(), VerdictMode::Claim);
        $second = $pipeline->judge('demo/refund', $args, $this->approvalGatedPack(), Hints::none(), VerdictMode::Claim);

        $this->assertSame($id, $first->reservedApprovalId);
        $this->assertTrue($second->proceeds());
        $this->assertTrue($second->claimed, 're-entry still satisfies the approval requirement');
        $this->assertNull($second->reservedApprovalId, 'only the FIRST claim owns the reservation');
        $this->assertSame('in_flight', $approvals->rows[$id]['status']);
    }

    public function testRepeatedJudgementsOfADenialAuditExactlyOnce(): void
    {
        // WordPress invokes an ability's permission callback repeatedly within
        // one REST request (~11 times observed live on WP 7.0). One denied
        // call must produce ONE audit row, naming the rule that denied it.
        $cap = new ArgumentCap('refund_total', 'demo/*', 'amount', maxPerCall: 100.0);
        $pack = new Pack(name: 'capped-args', allow: ['demo/*'], argumentCaps: [$cap]);
        $sink = new InMemoryAuditSink();
        $pipeline = $this->pipeline(['demo/refund' => Tier::Reversible], new DecisionRecorder($sink));

        for ($i = 0; $i < 11; $i++) {
            $this->assertFalse($pipeline->judge('demo/refund', ['amount' => 500], $pack, Hints::none(), VerdictMode::Claim)->proceeds());
        }

        $this->assertCount(1, $sink->records);
        $this->assertSame('argument_cap_refund_total_max_per_call', $sink->records[0]->toArray()['reason']);
    }

    // --- Self-reported hints -------------------------------------------------

    /** @dataProvider modes */
    public function testDestructiveHintBelowApprovalTierElevatesToApprovalRequired(VerdictMode $mode): void
    {
        // "demo/write" is Tier 1, which this pack allows outright -- exactly the
        // case a destructiveHint must be able to escalate.
        $pipeline = $this->pipeline(['demo/write' => Tier::SideEffecting]);

        $plain = $pipeline->judge('demo/write', [], $this->approvalGatedPack(), Hints::none(), $mode);
        $hinted = $pipeline->judge('demo/write', [], $this->approvalGatedPack(), new Hints(destructive: true), $mode);

        $this->assertTrue($plain->proceeds());
        $this->assertSame(Outcome::ApprovalRequired, $hinted->decision->outcome);
        $this->assertSame(Tier::Irreversible, $hinted->decision->tier);
    }

    /** @dataProvider modes */
    public function testDestructiveHintOnAPackThatHardDeniesTier2IsDeniedNotJustGatedOnApproval(VerdictMode $mode): void
    {
        $pack = new Pack(name: 'walled', allow: ['demo/*'], denyClass: ['tier2']);
        $pipeline = $this->pipeline(['demo/write' => Tier::SideEffecting]);

        $verdict = $pipeline->judge('demo/write', [], $pack, new Hints(destructive: true), $mode);

        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('denied_by_class_destructive_hint', $verdict->decision->reason);
    }

    /** @dataProvider modes */
    public function testDestructiveHintOnAnAlreadyIrreversibleVerbChangesNothing(VerdictMode $mode): void
    {
        // Already at the top tier -> the elevation's early return leaves the
        // ApprovalRequired verdict exactly as the core Gate produced it.
        $pipeline = $this->pipeline(['demo/refund' => Tier::Irreversible]);

        $plain = $pipeline->judge('demo/refund', [], $this->approvalGatedPack(), Hints::none(), $mode);
        $hinted = $pipeline->judge('demo/refund', [], $this->approvalGatedPack(), new Hints(destructive: true), $mode);

        $this->assertSame($plain->decision->outcome, $hinted->decision->outcome);
        $this->assertSame($plain->decision->reason, $hinted->decision->reason);
        $this->assertSame($plain->decision->tier, $hinted->decision->tier);
    }

    /** @dataProvider modes */
    public function testReadonlyHintNeverBypassesAnApprovalRequirement(VerdictMode $mode): void
    {
        // OUR catalog already classifies this as Tier 2; a self-reported
        // readonly hint must not let it slip through as allowed.
        $pipeline = $this->pipeline(['demo/refund' => Tier::Irreversible]);

        $verdict = $pipeline->judge('demo/refund', [], $this->approvalGatedPack(), new Hints(readonly: true), $mode);

        $this->assertFalse($verdict->proceeds());
    }

    public function testReadonlyHintedWriteVerbFailsClosedAsALyingAnnotation(): void
    {
        // A verb OUR catalog says writes, self-reported as read-only, is denied
        // outright even on an otherwise unrestricted pack.
        $pack = new Pack(name: 'owner', allow: ['*']);
        $pipeline = $this->pipeline(['demo/write' => Tier::SideEffecting]);

        $verdict = $pipeline->judge('demo/write', [], $pack, new Hints(readonly: true), VerdictMode::Claim);

        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('readonly_but_writes', $verdict->decision->reason);
    }

    public function testHintsNoneLeavesTheCoreDecisionUntouched(): void
    {
        $pack = new Pack(name: 'owner', allow: ['*']);
        $pipeline = $this->pipeline(['demo/write' => Tier::SideEffecting]);

        $verdict = $pipeline->judge('demo/write', ['id' => 1], $pack, Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds());
        $this->assertSame(Tier::SideEffecting, $verdict->decision->tier);
    }

    // --- The frozen WP_Error contract ---------------------------------------
    // The exact shape is pinned against the shared fixture in
    // VerdictErrorContractTest; what these add is that a real pipeline run
    // actually reaches those shapes (a deny with no tier, a park with and
    // without a persisted approval id).

    public function testErrorIsNullWhenTheVerdictProceeds(): void
    {
        $pack = new Pack(name: 'owner', allow: ['*']);
        $pipeline = $this->pipeline(['demo/read' => Tier::Reversible]);

        $this->assertNull($pipeline->judge('demo/read', [], $pack, Hints::none(), VerdictMode::Claim)->error());
    }

    public function testDenyErrorCarriesTheFrozenCodeAndDataKeys(): void
    {
        // An unknown verb denies with NO tier -- the `tier` key must still be
        // present (null-valued) on the deny error, unlike the approval one.
        $pack = new Pack(name: 'owner', allow: ['*']);
        $pipeline = $this->pipeline([]);

        $error = $pipeline->judge('demo/mystery', [], $pack, Hints::none(), VerdictMode::Claim)->error();

        $this->assertNotNull($error);
        $this->assertSame('agent_safety_denied', $error->get_error_code());
        $data = $error->get_error_data();
        $this->assertSame(403, $data['status']);
        $this->assertSame('demo/mystery', $data['verb']);
        $this->assertArrayHasKey('tier', $data);
        $this->assertNull($data['tier']);
    }

    public function testApprovalErrorOmitsTheApprovalIdKeyWhenNothingWasPersisted(): void
    {
        // No approval store -> nothing to persist, so array_filter drops the
        // key entirely rather than reporting a null id to the agent. (`tier` is
        // never null on this path: Decision::approvalRequired() demands one.)
        $pipeline = $this->pipeline(['demo/refund' => Tier::Irreversible]);

        $error = $pipeline->judge('demo/refund', [], $this->approvalGatedPack(), Hints::none(), VerdictMode::Claim)->error();

        $this->assertNotNull($error);
        $this->assertSame('approval_required', $error->get_error_code());
        $data = $error->get_error_data();
        $this->assertSame(202, $data['status']);
        $this->assertSame('demo/refund', $data['verb']);
        $this->assertSame(Tier::Irreversible->value, $data['tier']);
        $this->assertArrayNotHasKey('approval_id', $data);
    }

    public function testApprovalErrorCarriesTheApprovalIdWhenOneWasPersisted(): void
    {
        $approvals = new FakeApprovalStore();
        $pipeline = $this->pipeline(
            ['demo/refund' => Tier::Irreversible],
            new DecisionRecorder(new InMemoryAuditSink(), $approvals),
            $approvals,
        );

        $verdict = $pipeline->judge('demo/refund', [], $this->approvalGatedPack(), Hints::none(), VerdictMode::Claim);
        $error = $verdict->error();

        $this->assertNotNull($error);
        $this->assertSame($verdict->approvalId, $error->get_error_data()['approval_id']);
    }
}
