<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Approval\Grant;
use Specflux\AgentSafety\Approval\GrantStatus;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\GrantRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\SummaryMarkup;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeGrantStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Plugin\Verdict\GrantGate;

/**
 * The four independent conditions that must ALL hold before a pre-approval
 * grant authorises anything, and the release paths that make a refusal cost the
 * human's budget nothing.
 */
final class GrantGateTest extends TestCase
{
    private const SUBJECT = 'test:token';
    private const CORRELATION = 'senroflux:run:7';
    private const VERB = 'demo/publish';

    protected function setUp(): void
    {
        remove_all_filters('agent_safety_enable_grants');
        remove_all_filters('agent_safety_grant_eligible');
        remove_all_filters('agent_safety_approval_summary');
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: [self::SUBJECT]),
        ]));
    }

    protected function tearDown(): void
    {
        remove_all_filters('agent_safety_enable_grants');
        remove_all_filters('agent_safety_grant_eligible');
        remove_all_filters('agent_safety_approval_summary');
        RequestContext::reset();
    }

    private function enableGrants(): void
    {
        add_filter('agent_safety_enable_grants', static fn (): bool => true);
    }

    private function allowEverything(): void
    {
        add_filter('agent_safety_grant_eligible', static fn (): bool => true);
    }

    /**
     * @param array{grants?: FakeGrantStore, approvals?: FakeApprovalStore, sink?: InMemoryAuditSink} $parts
     */
    private function gate(array $parts = []): GrantGate
    {
        $approvals = $parts['approvals'] ?? new FakeApprovalStore();

        return new GrantGate(
            $parts['grants'] ?? new FakeGrantStore(),
            $approvals,
            new DecisionRecorder(null, $approvals),
            new GrantRecorder($parts['sink'] ?? null),
        );
    }

    // --- the enable switch ---------------------------------------------------

    public function testTheFeatureIsOffUntilTheSiteSwitchesItOn(): void
    {
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, 'step_1');
        $this->allowEverything();

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['id' => 1])
        );

        $this->assertNull($spent);
        // Nothing was spent, either.
        $this->assertSame(2, $grants->get('gnt_fake_1')?->remainingCount);
    }

    public function testATruthyButNonTrueEnableFilterStillReadsAsOff(): void
    {
        add_filter('agent_safety_enable_grants', static fn (): string => 'yes');
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['id' => 1])
        );

        $this->assertNull($spent);
    }

    // --- the eligibility filter ---------------------------------------------

    public function testAnUnhookedEligibilityFilterParksTheCall(): void
    {
        // The DEFAULT-FALSE rule: an active, perfectly matching grant is not
        // enough on its own. A missing hook must mean "no grant applies", never
        // "every grant applies to any object".
        $this->enableGrants();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['id' => 1])
        );

        $this->assertNull($spent);
        // …and the perfectly good grant is untouched.
        $this->assertSame(2, $grants->get('gnt_fake_1')?->remainingCount);
    }

    public function testAnIneligibleCallGivesTheReservationBack(): void
    {
        $this->enableGrants();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);
        add_filter('agent_safety_grant_eligible', static fn (): bool => false);

        RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['id' => 1])
        );

        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
        $this->assertSame(GrantStatus::Active, $grants->get($grantId)?->status);
    }

    public function testTheFilterReceivesTheGrantTheVerbAndTheRealArgs(): void
    {
        $this->enableGrants();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, 'step_9');
        $seen = [];
        add_filter(
            'agent_safety_grant_eligible',
            static function (bool $eligible, Grant $grant, string $verb, array $args) use (&$seen): bool {
                $seen = ['default' => $eligible, 'grant' => $grant, 'verb' => $verb, 'args' => $args];

                return true;
            },
            10,
            4
        );

        RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['post_id' => 42])
        );

        $this->assertFalse($seen['default'], 'The filter must be handed false, not true.');
        $this->assertSame(self::VERB, $seen['verb']);
        $this->assertSame(['post_id' => 42], $seen['args']);
        $this->assertSame('step_9', $seen['grant']->planStepId);
        // Post-decrement: the host sees the budget as it now stands.
        $this->assertSame(1, $seen['grant']->remainingCount);
    }

    // --- the match itself ----------------------------------------------------

    public function testAMatchingGrantMintsAnApprovalBoundToTheRealArgs(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, 'step_1');
        $approvals = new FakeApprovalStore();

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants, 'approvals' => $approvals])
                ->mint(self::VERB, ['post_id' => 42])
        );

        $this->assertSame($grantId, $spent);
        $this->assertCount(1, $approvals->mintCalls);
        $this->assertSame(
            ApprovalBinding::hash(self::VERB, ['post_id' => 42]),
            $approvals->mintCalls[0]['args_hash']
        );
        $this->assertSame(self::SUBJECT, $approvals->mintCalls[0]['subject']);
        // The approver on the minted row is the GRANTOR, never the agent.
        $this->assertSame(5, $approvals->mintCalls[0]['approver']);
        $this->assertSame($grantId, $approvals->mintCalls[0]['grant_id']);
        $this->assertSame(1, $grants->get($grantId)?->remainingCount);
    }

    public function testAGrantFromAnotherCorrelationNeverMatches(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, self::SUBJECT, 'senroflux:run:1', 5, null);

        $spent = RequestContext::withCorrelation(
            'senroflux:run:2',
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['post_id' => 42])
        );

        $this->assertNull($spent);
    }

    public function testAnUnauthenticatedCallerNeverMatches(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([new FakeIdentityProvider(currentTokens: [])]));
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, null, self::CORRELATION, 5, null);

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['post_id' => 42])
        );

        $this->assertNull($spent);
    }

    public function testAnExhaustedGrantNeverMatchesAgain(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 1, self::SUBJECT, self::CORRELATION, 5, null);
        $gate = $this->gate(['grants' => $grants]);

        $first = RequestContext::withCorrelation(self::CORRELATION, fn (): ?string => $gate->mint(self::VERB, ['a' => 1]));
        $second = RequestContext::withCorrelation(self::CORRELATION, fn (): ?string => $gate->mint(self::VERB, ['a' => 2]));

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    public function testAGrantWhoseTtlLapsedNeverMatches(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grants->put(new Grant(
            grantId: 'gnt_old',
            correlationId: self::CORRELATION,
            verb: self::VERB,
            remainingCount: 99,
            subject: self::SUBJECT,
            grantedBy: 5,
            planStepId: null,
            createdTs: '2020-01-01 00:00:00',
            expiresTs: '2020-01-02 00:00:00',
            revokedTs: null,
            status: GrantStatus::Active,
        ));

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['a' => 1])
        );

        $this->assertNull($spent);
    }

    public function testARevokedGrantNeverMatches(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 5, self::SUBJECT, self::CORRELATION, 5, null);
        $grants->revokeByCorrelation(self::CORRELATION);

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['a' => 1])
        );

        $this->assertNull($spent);
        $this->assertSame(5, $grants->get($grantId)?->remainingCount);
    }

    // --- failure paths give the reservation back -----------------------------

    public function testAFailedMintGivesTheReservationBack(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);
        $approvals = new FakeApprovalStore();
        $approvals->mintFails = true;

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants, 'approvals' => $approvals])
                ->mint(self::VERB, ['a' => 1])
        );

        $this->assertNull($spent);
        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
    }

    public function testAnUnreadableGrantAfterReserveGivesTheReservationBack(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);
        $grants->getReturnsNull = true;

        $spent = RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['a' => 1])
        );

        $this->assertNull($spent);
        $grants->getReturnsNull = false;
        $this->assertSame(2, $grants->get('gnt_fake_1')?->remainingCount);
    }

    public function testAGateWithNoStoreCanNeverAuthoriseAnything(): void
    {
        $this->enableGrants();
        $this->allowEverything();

        $this->assertFalse((new GrantGate())->available());
        $this->assertNull(RequestContext::withCorrelation(
            self::CORRELATION,
            static fn (): ?string => (new GrantGate())->mint(self::VERB, ['a' => 1])
        ));
    }

    public function testReleaseToleratesNothingHavingBeenReserved(): void
    {
        $this->gate()->release(null);
        $this->gate()->release('');

        $this->expectNotToPerformAssertions();
    }

    // --- audit ---------------------------------------------------------------

    public function testSpendingTheLastReservationAuditsGrantExhausted(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 1, self::SUBJECT, self::CORRELATION, 5, 'step_3');
        $sink = new InMemoryAuditSink();

        RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants, 'sink' => $sink])->mint(self::VERB, ['a' => 1])
        );

        $this->assertCount(1, $sink->records);
        $record = $sink->records[0]->toArray();
        $this->assertSame(GrantRecorder::EVENT_EXHAUSTED, $record['reason']);
        $this->assertSame('grant', $record['decision']);
        $this->assertSame(self::CORRELATION, $record['correlation_id']);
        $this->assertSame(self::VERB, $record['ability']);
        $this->assertSame('step_3', $record['input']['plan_step_id']);
        $this->assertSame(5, $record['approval']['approver']);
    }

    public function testAGrantWithBudgetLeftAuditsNothing(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 3, self::SUBJECT, self::CORRELATION, 5, null);
        $sink = new InMemoryAuditSink();

        RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants, 'sink' => $sink])->mint(self::VERB, ['a' => 1])
        );

        $this->assertSame([], $sink->records);
    }

    // --- AS-11 interop -------------------------------------------------------

    public function testAGrantMintedRowFiresTheApprovalSummaryFilter(): void
    {
        $this->enableGrants();
        $this->allowEverything();
        add_filter(
            'agent_safety_approval_summary',
            static fn (string $summary, string $verb): string => '<a href="/preview">Publish ' . $verb . '</a>',
            10,
            3
        );
        $grants = new FakeGrantStore();
        $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);
        $approvals = new FakeApprovalStore();

        RequestContext::withCorrelation(
            self::CORRELATION,
            fn (): ?string => $this->gate(['grants' => $grants, 'approvals' => $approvals])
                ->mint(self::VERB, ['a' => 1])
        );

        $this->assertCount(1, $approvals->mintCalls);
        $stored = $approvals->mintCalls[0]['summary'];
        $this->assertTrue(SummaryMarkup::isHostAuthored($stored));
        $this->assertSame('<a href="/preview">Publish demo/publish</a>', SummaryMarkup::unwrap($stored));
    }

    public function testAThrowingEligibilityFilterStillGivesTheReservationBack(): void
    {
        // The filter is HOST code. An exception in it must not quietly charge a
        // human's budget for a call that never ran.
        $this->enableGrants();
        $grants = new FakeGrantStore();
        $grantId = $grants->issue(self::VERB, 2, self::SUBJECT, self::CORRELATION, 5, null);
        add_filter('agent_safety_grant_eligible', static function (): bool {
            throw new \RuntimeException('the host blew up');
        });

        try {
            RequestContext::withCorrelation(
                self::CORRELATION,
                fn (): ?string => $this->gate(['grants' => $grants])->mint(self::VERB, ['a' => 1])
            );
            $this->fail('The host exception should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('the host blew up', $e->getMessage());
        }

        $this->assertSame(2, $grants->get($grantId)?->remainingCount);
    }
}
