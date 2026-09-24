<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Approval\StateFingerprint;
use Specflux\AgentSafety\Plugin\Approval\StateProbe;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Plugin\Verdict\ApprovalMessageVariant;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use Specflux\AgentSafety\Plugin\Verdict\VerdictMode;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * AS-6: the state-fingerprint behaviour {@see VerdictPipeline} adds on top of
 * the approval flow already proven in {@see VerdictPipelineTest} — claim-time
 * mismatch (a target that changed between request and claim), probe failure
 * (`state_unverifiable`), the no-probe-declared no-op path, and the shadow
 * interaction.
 */
final class VerdictPipelineFingerprintTest extends TestCase
{
    private const VERB = 'demo/refund';

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
        $GLOBALS['wpas_test_time'] = \time();
    }

    private function stubProbe(?array $result, bool $throws = false): StateProbe
    {
        return new class ($result, $throws) implements StateProbe {
            public function __construct(private readonly ?array $result, private readonly bool $throws)
            {
            }

            public function read(string $verb, array $args): ?array
            {
                if ($this->throws) {
                    throw new \RuntimeException('probe blew up');
                }

                return $this->result;
            }
        };
    }

    /**
     * @param array<string, StateProbe> $stateProbes
     */
    private function pipeline(FakeApprovalStore $approvals, InMemoryAuditSink $sink, array $stateProbes): VerdictPipeline
    {
        $catalog = new VerbCatalog();
        $catalog->register([self::VERB => Tier::Irreversible]);

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));

        return new VerdictPipeline(
            gate: new Gate(new TierClassifier($catalog)),
            recorder: new DecisionRecorder($sink, $approvals),
            approvals: $approvals,
            stateProbes: $stateProbes,
        );
    }

    private function pack(): Pack
    {
        return new Pack(name: 'support', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
    }

    // --- Claim-time mismatch --------------------------------------------------

    public function testClaimTimeFingerprintMismatchMarksOldApprovalStaleAndFilesFreshPendingRequest(): void
    {
        $args = ['id' => 1, 'amount' => 20];
        $argsHash = ApprovalBinding::hash(self::VERB, $args);
        $oldFingerprint = StateFingerprint::compute(['modified' => 100, 'status' => 'processing']);

        $approvals = new FakeApprovalStore();
        $oldId = $approvals->seedApprovedWithFingerprint(self::VERB, $argsHash, 'test:token', $oldFingerprint);

        $probe = $this->stubProbe(['modified' => 200, 'status' => 'completed']); // a DIFFERENT result
        $sink = new InMemoryAuditSink();
        $pipeline = $this->pipeline($approvals, $sink, [self::VERB => $probe]);

        $verdict = $pipeline->judge(self::VERB, $args, $this->pack(), Hints::none(), VerdictMode::Claim);

        $this->assertSame('stale', $approvals->rows[$oldId]['status'], 'the old approval must be marked stale, never claimed');

        $this->assertCount(1, $approvals->requestCalls, 'a fresh pending request must be filed');
        $fresh = $approvals->requestCalls[0];
        $this->assertSame(self::VERB, $fresh['verb']);
        $this->assertSame($argsHash, $fresh['args_hash']);
        $this->assertSame('probe', $fresh['fingerprint_kind']);
        $this->assertNotSame($oldFingerprint, $fresh['fingerprint'], 'the fresh row must carry the CURRENT fingerprint');

        $this->assertNotNull($verdict->approvalId);
        $this->assertNotSame($oldId, $verdict->approvalId, 'the verdict must carry the NEW approval id, not the stale one');
        $this->assertFalse($verdict->claimed);
        $this->assertFalse($verdict->proceeds());

        $error = $verdict->error();
        $this->assertNotNull($error);
        $this->assertSame('approval_required', $error->get_error_code());
        $this->assertSame($verdict->approvalId, $error->get_error_data()['approval_id']);
        $this->assertStringContainsString('needs human approval again', $error->get_error_message());
        $this->assertStringContainsString('target changed after the earlier approval', $error->get_error_message());
    }

    public function testVerdictErrorFixtureFileIsUntouchedByTheStaleMessageChange(): void
    {
        $root = dirname(__DIR__, 2);
        $diff = [];
        exec(
            'git -C ' . escapeshellarg($root) . ' diff --exit-code -- tests/Fixtures/VerdictErrorFixture.php',
            $diff,
            $exit
        );
        $this->assertSame(0, $exit, 'VerdictErrorFixture.php must not change: ' . implode("\n", $diff));
    }

    // --- Probe failure => state_unverifiable ----------------------------------

    public function testProbeThrowingDeniesStateUnverifiableInPeekModeAndFilesNoPendingRequest(): void
    {
        $args = ['id' => 1];
        $approvals = new FakeApprovalStore();
        $sink = new InMemoryAuditSink();
        $pipeline = $this->pipeline($approvals, $sink, [self::VERB => $this->stubProbe(null, throws: true)]);

        $verdict = $pipeline->judge(self::VERB, $args, $this->pack(), Hints::none(), VerdictMode::Peek);

        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('state_unverifiable', $verdict->decision->reason);
        $this->assertFalse($verdict->proceeds());
        $this->assertSame([], $approvals->requestCalls, 'a call that cannot be verified must never be parked as approval-required');

        $error = $verdict->error();
        $this->assertNotNull($error);
        $this->assertSame('agent_safety_denied', $error->get_error_code());
    }

    public function testProbeReturningNullDeniesStateUnverifiableAtClaimTimeAndReservesNothing(): void
    {
        $args = ['id' => 1];
        $approvals = new FakeApprovalStore();
        $sink = new InMemoryAuditSink();
        $pipeline = $this->pipeline($approvals, $sink, [self::VERB => $this->stubProbe(null)]); // target gone

        $verdict = $pipeline->judge(self::VERB, $args, $this->pack(), Hints::none(), VerdictMode::Claim);

        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('state_unverifiable', $verdict->decision->reason);
        $this->assertNull($verdict->reservedApprovalId);
        $this->assertFalse($verdict->claimed);
        $this->assertSame([], $approvals->requestCalls);
    }

    // --- No probe declared: byte-for-byte pre-AS-6 behaviour ------------------

    public function testNoProbeDeclaredParksWithAnUnfingerprintedPendingRequest(): void
    {
        $args = ['id' => 1, 'amount' => 20];
        $approvals = new FakeApprovalStore();
        $sink = new InMemoryAuditSink();
        $pipeline = $this->pipeline($approvals, $sink, []); // no probes registered at all

        $verdict = $pipeline->judge(self::VERB, $args, $this->pack(), Hints::none(), VerdictMode::Claim);

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertCount(1, $approvals->requestCalls);
        $this->assertNull($approvals->requestCalls[0]['fingerprint']);
        $this->assertSame('none', $approvals->requestCalls[0]['fingerprint_kind']);
        $this->assertSame(ApprovalMessageVariant::Base, $this->variantOf($verdict));
    }

    private function variantOf(\Specflux\AgentSafety\Plugin\Verdict\Verdict $verdict): ApprovalMessageVariant
    {
        // No public accessor for the variant beyond error()'s rendered text;
        // the base message is asserted directly here rather than reaching
        // into a private property.
        $error = $verdict->error();
        $this->assertNotNull($error);
        $this->assertStringNotContainsString('needs human approval again', $error->get_error_message());

        return ApprovalMessageVariant::Base;
    }

    // --- Shadow interaction ----------------------------------------------------

    public function testShadowedClaimMismatchAuditsBothHashesAsADryRunAndFilesNoNewPendingRow(): void
    {
        $args = ['id' => 1, 'amount' => 20];
        $argsHash = ApprovalBinding::hash(self::VERB, $args);
        $oldFingerprint = StateFingerprint::compute(['modified' => 100, 'status' => 'processing']);

        $approvals = new FakeApprovalStore();
        $oldId = $approvals->seedApprovedWithFingerprint(self::VERB, $argsHash, 'test:token', $oldFingerprint);

        $probe = $this->stubProbe(['modified' => 200, 'status' => 'completed']);
        $sink = new InMemoryAuditSink();
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support' => (int) \time() + 3600];
        $pipeline = $this->pipeline($approvals, $sink, [self::VERB => $probe]);

        $verdict = $pipeline->judge(self::VERB, $args, $this->pack(), Hints::none(), VerdictMode::Claim);

        $this->assertTrue($verdict->proceeds(), 'a shadowed pack must let the call run');
        $this->assertTrue($verdict->shadowed);
        $this->assertSame('stale', $approvals->rows[$oldId]['status']);
        $this->assertSame([], $approvals->requestCalls, 'shadow must skip filing a fresh pending row (§3.3 item 11)');

        $staleRecords = array_values(array_filter(
            $sink->records,
            static fn ($r): bool => $r->reason === 'approval.stale',
        ));
        $this->assertCount(1, $staleRecords);
        $record = $staleRecords[0];
        $this->assertTrue($record->dryRun);
        $this->assertSame($oldFingerprint, $record->input['old_fingerprint']);
        $this->assertNotNull($record->input['new_fingerprint']);
        $this->assertNotSame($oldFingerprint, $record->input['new_fingerprint']);
    }
}
