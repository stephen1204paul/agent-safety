<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\GrantRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeGrantStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Verdict\GrantGate;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use Specflux\AgentSafety\Plugin\Verdict\VerdictMode;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * The pre-approval grant branch as the PIPELINE sees it (AS-12): when a grant
 * may satisfy an approval-required call, when it may not, and what happens to
 * the count on every path the call does not execute on.
 *
 * The four-condition matrix itself lives in {@see GrantGateTest}; what is proven
 * here is the ORDER — an exact human approval is always spent first, a grant is
 * claim-mode only, and it never rescues a call blocked for any other reason.
 */
final class VerdictPipelineGrantTest extends TestCase
{
    private const SUBJECT = 'test:token';
    private const CORRELATION = 'senroflux:run:7';
    private const VERB = 'demo/publish';

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        remove_all_filters('agent_safety_enable_grants');
        remove_all_filters('agent_safety_grant_eligible');
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        remove_all_filters('agent_safety_enable_grants');
        remove_all_filters('agent_safety_grant_eligible');
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    private function enableGrants(bool $eligible = true): void
    {
        add_filter('agent_safety_enable_grants', static fn (): bool => true);
        add_filter('agent_safety_grant_eligible', static fn (): bool => $eligible);
    }

    /** A pack that allows the demo verbs outright but gates tier 2 on approval. */
    private function pack(): Pack
    {
        return new Pack(name: 'support', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
    }

    /**
     * @param array<string, Tier> $verbToTier
     */
    private function pipeline(
        FakeApprovalStore $approvals,
        FakeGrantStore $grants,
        array $verbToTier = [self::VERB => Tier::Irreversible],
        ?Pack $pack = null,
    ): VerdictPipeline {
        $catalog = new VerbCatalog();
        $catalog->register($verbToTier);

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: [self::SUBJECT]),
        ]));

        $recorder = new DecisionRecorder(null, $approvals);

        return new VerdictPipeline(
            new Gate(new TierClassifier($catalog)),
            $recorder,
            $approvals,
            grants: new GrantGate($grants, $approvals, $recorder, new GrantRecorder()),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function judge(
        VerdictPipeline $pipeline,
        array $args = ['post_id' => 42],
        VerdictMode $mode = VerdictMode::Claim,
        ?Pack $pack = null,
    ): \Specflux\AgentSafety\Plugin\Verdict\Verdict {
        return RequestContext::withCorrelation(
            self::CORRELATION,
            fn () => $pipeline->judge(self::VERB, $args, $pack ?? $this->pack(), Hints::none(), $mode)
        );
    }

    public function testAnEligibleGrantAllowsAnOtherwiseApprovalRequiredCall(): void
    {
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, 'step_1');

        $verdict = $this->judge($this->pipeline($approvals, $grants));

        $this->assertSame(Outcome::Allow, $verdict->decision->outcome);
        $this->assertTrue($verdict->claimed);
        $this->assertSame($grantId, $verdict->grantId);
        $this->assertNotNull($verdict->reservedApprovalId);
        // The minted row went through the ordinary reserve path.
        $this->assertSame('in_flight', $approvals->rows[$verdict->reservedApprovalId]['status']);
        $this->assertSame(1, $grants->get($grantId)?->remainingCount);
    }

    public function testWithoutAGrantTheCallStillParks(): void
    {
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();

        $verdict = $this->judge($this->pipeline($approvals, $grants));

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertNull($verdict->grantId);
        $this->assertNotNull($verdict->approvalId);
    }

    public function testAnUnhookedEligibilityFilterParksTheCall(): void
    {
        // The headline fail-closed property: grants ON, a perfectly matching
        // grant present, no host hook — and the call still parks for a human.
        add_filter('agent_safety_enable_grants', static fn (): bool => true);
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);

        $verdict = $this->judge($this->pipeline($approvals, $grants));

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertNull($verdict->grantId);
        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
    }

    public function testAnExactHumanApprovalIsSpentBeforeAnyGrant(): void
    {
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);
        $pipeline = $this->pipeline($approvals, $grants);
        $approvalId = $approvals->seedApproved(
            self::VERB,
            \Specflux\AgentSafety\Approval\ApprovalBinding::hash(self::VERB, ['post_id' => 42]),
            self::SUBJECT,
        );

        $verdict = $this->judge($pipeline);

        $this->assertSame($approvalId, $verdict->reservedApprovalId);
        $this->assertNull($verdict->grantId, 'A human decision about THIS action outranks a standing grant.');
        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
    }

    public function testAPeekingSeamNeverMintsAndParksInstead(): void
    {
        // Minting is a side effect; the peek seam exists to decide without one.
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);

        $verdict = $this->judge($this->pipeline($approvals, $grants), mode: VerdictMode::Peek);

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertSame([], $approvals->mintCalls);
        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
    }

    public function testAGrantNeverRescuesACallBlockedForAnyOtherReason(): void
    {
        // not_in_pack: the reservation side effect must not fire while a deny
        // gate would still block the call.
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);
        $pipeline = $this->pipeline($approvals, $grants);

        $verdict = $this->judge($pipeline, pack: new Pack(name: 'locked', allow: ['other/*']));

        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('not_in_pack', $verdict->decision->reason);
        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
    }

    public function testAnUnknownVerbIsNeverRescuedByAGrant(): void
    {
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grants->issue('demo/unmapped', 2, self::SUBJECT, self::CORRELATION, 5, null);
        $pipeline = $this->pipeline($approvals, $grants);

        $verdict = RequestContext::withCorrelation(
            self::CORRELATION,
            fn () => $pipeline->judge('demo/unmapped', ['a' => 1], $this->pack(), Hints::none(), VerdictMode::Claim)
        );

        $this->assertSame('unknown_verb', $verdict->decision->reason);
        $this->assertSame([], $approvals->mintCalls);
    }

    public function testARateLimitTrippedAfterTheMintGivesTheCountBack(): void
    {
        // The grant was spent, then a later gate blocked the call anyway: the
        // human's budget must not be charged for an action that will not run.
        $GLOBALS['wpas_test_time'] = 1_700_000_000;
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 3, self::SUBJECT, self::CORRELATION, 5, null);
        $pack = new Pack(
            name: 'capped',
            allow: ['demo/*'],
            approvalByClass: ['tier2' => true],
            limits: ['calls_per_minute' => 0],
        );

        $verdict = $this->judge($this->pipeline($approvals, $grants), pack: $pack);

        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertStringStartsWith('rate_limited_', $verdict->decision->reason);
        $this->assertNull($verdict->grantId);
        $this->assertSame(3, $grants->get($grantId)?->remainingCount);
    }

    public function testAnArgumentCapTrippedAfterTheMintGivesTheCountBack(): void
    {
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 3, self::SUBJECT, self::CORRELATION, 5, null);
        $pack = new Pack(
            name: 'capped',
            allow: ['demo/*'],
            approvalByClass: ['tier2' => true],
            argumentCaps: [new ArgumentCap(
                id: 'amount',
                verbs: 'demo/*',
                argPath: 'amount',
                maxPerCall: 10.0,
            )],
        );

        $verdict = $this->judge($this->pipeline($approvals, $grants), args: ['amount' => 500], pack: $pack);

        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertNull($verdict->grantId);
        $this->assertSame(3, $grants->get($grantId)?->remainingCount);
    }

    public function testAReenteredPermissionCheckMintsExactlyOnce(): void
    {
        // WordPress re-enters a permission callback many times per REST request;
        // each re-entry must NOT spend another reservation.
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 3, self::SUBJECT, self::CORRELATION, 5, null);
        $pipeline = $this->pipeline($approvals, $grants);

        $first = $this->judge($pipeline);
        $second = $this->judge($pipeline);
        $third = $this->judge($pipeline);

        $this->assertSame($grantId, $first->grantId);
        $this->assertNull($second->grantId);
        $this->assertNull($third->grantId);
        $this->assertTrue($second->decision->isAllowed());
        $this->assertCount(1, $approvals->mintCalls);
        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
    }

    public function testTwoRunsTickedInOneProcessNeverSeeEachOthersGrants(): void
    {
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $grants = new FakeGrantStore();
        $runOne = $grants->issue(self::VERB, 2, self::SUBJECT, 'senroflux:run:1', 5, null);
        $pipeline = $this->pipeline($approvals, $grants);

        $inRunOne = RequestContext::withCorrelation(
            'senroflux:run:1',
            fn () => $pipeline->judge(self::VERB, ['post_id' => 1], $this->pack(), Hints::none(), VerdictMode::Claim)
        );
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: [self::SUBJECT]),
        ]));
        $inRunTwo = RequestContext::withCorrelation(
            'senroflux:run:2',
            fn () => $pipeline->judge(self::VERB, ['post_id' => 2], $this->pack(), Hints::none(), VerdictMode::Claim)
        );

        $this->assertSame($runOne, $inRunOne->grantId);
        $this->assertSame(Outcome::ApprovalRequired, $inRunTwo->decision->outcome);
        $this->assertNull($inRunTwo->grantId, "Run 2 must not reach run 1's grant.");
        $this->assertSame(1, $grants->get($runOne)?->remainingCount);
    }

    public function testAPipelineWithNoGrantGateBehavesExactlyAsBefore(): void
    {
        $this->enableGrants();
        $approvals = new FakeApprovalStore();
        $catalog = new VerbCatalog();
        $catalog->register([self::VERB => Tier::Irreversible]);
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: [self::SUBJECT]),
        ]));
        $pipeline = new VerdictPipeline(
            new Gate(new TierClassifier($catalog)),
            new DecisionRecorder(null, $approvals),
            $approvals,
        );

        $verdict = $this->judge($pipeline);

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertNull($verdict->grantId);
    }
}
