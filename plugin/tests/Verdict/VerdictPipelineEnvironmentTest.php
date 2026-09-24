<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\EnvironmentGuard;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
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
 * AS-7 §3.4 item 6 and item 4's wiring into {@see VerdictPipeline}: a retry
 * against an approval a site-binding mismatch already voided takes the SAME
 * fresh-request path as a stale claim (AS-6), but with the site-moved
 * message; and {@see EnvironmentGuard::ensureCurrent()} runs on every judged
 * call, before the ordinary evaluation.
 */
final class VerdictPipelineEnvironmentTest extends TestCase
{
    private const VERB = 'demo/refund';

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_home_url'] = 'https://example.com';
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
        unset($GLOBALS['wpas_test_home_url']);
    }

    private function pack(): Pack
    {
        return new Pack(name: 'support', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
    }

    private function configureIdentity(): void
    {
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));
    }

    public function testRetryAgainstAVoidedApprovalGetsTheSiteMovedMessageAndAFreshApprovalId(): void
    {
        $args = ['id' => 1, 'amount' => 20];
        $argsHash = ApprovalBinding::hash(self::VERB, $args);

        $approvals = new FakeApprovalStore();
        $voidedId = $approvals->seedVoidEnvironment(self::VERB, $argsHash, 'test:token');

        $this->configureIdentity();
        $catalog = new VerbCatalog();
        $catalog->register([self::VERB => Tier::Irreversible]);
        $sink = new InMemoryAuditSink();

        $pipeline = new VerdictPipeline(
            gate: new Gate(new TierClassifier($catalog)),
            recorder: new DecisionRecorder($sink, $approvals),
            approvals: $approvals,
        );

        $verdict = $pipeline->judge(self::VERB, $args, $this->pack(), Hints::none(), VerdictMode::Claim);

        $this->assertFalse($verdict->claimed);
        $this->assertFalse($verdict->proceeds());
        $this->assertNotNull($verdict->approvalId);
        $this->assertNotSame($voidedId, $verdict->approvalId, 'the verdict must carry a NEW approval id, not the voided one');
        $this->assertCount(1, $approvals->requestCalls, 'a fresh pending request must be filed');

        $error = $verdict->error();
        $this->assertNotNull($error);
        $this->assertSame('approval_required', $error->get_error_code());
        $this->assertSame($verdict->approvalId, $error->get_error_data()['approval_id']);
        $this->assertStringContainsString('needs human approval again', $error->get_error_message());
        $this->assertStringContainsString("site's address changed", $error->get_error_message());
    }

    public function testEnsureCurrentRunsOnEveryJudgedCallBeforeEvaluation(): void
    {
        // A bound host that no longer matches home_url() — the environment
        // check must run and void relaxations as a side effect of judge()
        // itself, with no separate call from the test.
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $GLOBALS['wpas_test_home_url'] = 'https://new-host.example';

        $sink = new InMemoryAuditSink();
        $environment = new EnvironmentGuard(new ShadowMode(), new AdminChangeRecorder($sink));

        $this->configureIdentity();
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible]);

        $pipeline = new VerdictPipeline(
            gate: new Gate(new TierClassifier($catalog)),
            recorder: new DecisionRecorder($sink),
            environment: $environment,
        );

        $pipeline->judge('demo/read', [], $this->pack(), Hints::none(), VerdictMode::Claim);

        $reasons = array_map(static fn ($r) => $r->reason, $sink->records);
        $this->assertContains(AdminChangeRecorder::EVENT_ENVIRONMENT_MISMATCH, $reasons);
    }

    public function testEnsureCurrentVoidsAtMostOnceAcrossRepeatedJudgedCalls(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $GLOBALS['wpas_test_home_url'] = 'https://new-host.example';

        $sink = new InMemoryAuditSink();
        $environment = new EnvironmentGuard(new ShadowMode(), new AdminChangeRecorder($sink));

        $this->configureIdentity();
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible]);

        $pipeline = new VerdictPipeline(
            gate: new Gate(new TierClassifier($catalog)),
            recorder: new DecisionRecorder($sink),
            environment: $environment,
        );

        $pipeline->judge('demo/read', [], $this->pack(), Hints::none(), VerdictMode::Claim);
        $pipeline->judge('demo/read', [], $this->pack(), Hints::none(), VerdictMode::Claim);
        $pipeline->judge('demo/read', [], $this->pack(), Hints::none(), VerdictMode::Claim);

        $mismatchRows = array_filter(
            $sink->records,
            static fn ($r) => $r->reason === AdminChangeRecorder::EVENT_ENVIRONMENT_MISMATCH,
        );
        $this->assertCount(1, $mismatchRows, 'the void must run exactly once no matter how many calls are judged');
    }
}
