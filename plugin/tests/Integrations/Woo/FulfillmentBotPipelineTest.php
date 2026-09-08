<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooIntegration;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooPacks;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
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
 * The shipped fulfillment-bot preset driven through the real
 * {@see VerdictPipeline} over the real Woo verb catalog and elevation rules
 * ({@see WooIntegration::register()}), so the status allow-list and the
 * forbidden keys are proven where they bind — after the tier gate, on a
 * decision that is otherwise Allow — and not only in the pure policy.
 */
final class FulfillmentBotPipelineTest extends TestCase
{
    private const ORDER_UPDATE = 'woocommerce/orders-update';
    private const TOKEN = 'wc:ck_fulfillment';

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

    public function testFulfillmentStatusIsAllowedWithoutApproval(): void
    {
        $verdict = $this->pipeline()->judge(
            self::ORDER_UPDATE,
            ['id' => 42, 'status' => 'processing'],
            $this->fulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertTrue($verdict->proceeds());
        // Elevated by OrderFulfillmentElevationRule, admitted because this pack does not gate tier 2.
        $this->assertSame(Tier::Irreversible, $verdict->decision->tier);
    }

    public function testAnUpdateThatSetsNoGovernedFieldIsAllowed(): void
    {
        $verdict = $this->pipeline()->judge(
            self::ORDER_UPDATE,
            ['id' => 42, 'customer_note' => 'Leave at the side door'],
            $this->fulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertTrue($verdict->proceeds());
    }

    public function testRefundedStatusIsDeniedNamingTheStatusCap(): void
    {
        $sink = new InMemoryAuditSink();

        $verdict = $this->pipeline(sink: $sink)->judge(
            self::ORDER_UPDATE,
            ['id' => 42, 'status' => 'refunded'],
            $this->fulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertFalse($verdict->proceeds());
        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame('argument_cap_order_status_not_allowed_value', $verdict->decision->reason);
        $this->assertCount(1, $sink->records);
        $this->assertSame('argument_cap_order_status_not_allowed_value', $sink->records[0]->toArray()['reason']);
    }

    public function testCancelledStatusIsDeniedBecauseThisPackNeverAsksAHuman(): void
    {
        $verdict = $this->pipeline()->judge(
            self::ORDER_UPDATE,
            ['id' => 42, 'status' => 'cancelled'],
            $this->fulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertFalse($verdict->proceeds());
        $this->assertSame('argument_cap_order_status_not_allowed_value', $verdict->decision->reason);
    }

    public function testBillingRewriteIsDeniedNamingTheForbiddenKey(): void
    {
        $verdict = $this->pipeline()->judge(
            self::ORDER_UPDATE,
            ['id' => 42, 'status' => 'processing', 'billing' => ['email' => 'mule@example.com']],
            $this->fulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertFalse($verdict->proceeds());
        $this->assertSame('argument_cap_billing_forbidden_argument', $verdict->decision->reason);
    }

    public function testSetPaidIsDeniedEvenWhenFalse(): void
    {
        $verdict = $this->pipeline()->judge(
            self::ORDER_UPDATE,
            ['id' => 42, 'set_paid' => false],
            $this->fulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertFalse($verdict->proceeds());
        $this->assertSame('argument_cap_set_paid_forbidden_argument', $verdict->decision->reason);
    }

    public function testStatusCapParksAnElevatedTransitionWhenThePackGatesTierTwo(): void
    {
        $approvals = new FakeApprovalStore();

        $verdict = $this->pipeline($approvals)->judge(
            self::ORDER_UPDATE,
            ['id' => 42, 'status' => 'completed'],
            $this->approvalGatedFulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        $this->assertNotNull($verdict->approvalId);
        $this->assertCount(1, $approvals->requestCalls);
    }

    public function testStatusCapDoesNotFightAHumanApprovalOfAnElevatedTransition(): void
    {
        $approvals = new FakeApprovalStore();
        $args = ['id' => 42, 'status' => 'completed'];
        $id = $approvals->seedApproved(self::ORDER_UPDATE, ApprovalBinding::hash(self::ORDER_UPDATE, $args), self::TOKEN);

        $verdict = $this->pipeline($approvals)->judge(
            self::ORDER_UPDATE,
            $args,
            $this->approvalGatedFulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertTrue($verdict->proceeds());
        $this->assertTrue($verdict->claimed);
        $this->assertSame($id, $verdict->reservedApprovalId);
    }

    public function testAHumanApprovalNeverOverridesTheStatusCap(): void
    {
        $approvals = new FakeApprovalStore();
        $args = ['id' => 42, 'status' => 'refunded'];
        $id = $approvals->seedApproved(self::ORDER_UPDATE, ApprovalBinding::hash(self::ORDER_UPDATE, $args), self::TOKEN);

        $verdict = $this->pipeline($approvals)->judge(
            self::ORDER_UPDATE,
            $args,
            $this->approvalGatedFulfillmentBot(),
            Hints::none(),
            VerdictMode::Claim,
        );

        $this->assertFalse($verdict->proceeds());
        $this->assertSame('argument_cap_order_status_not_allowed_value', $verdict->decision->reason);
        // The grant was spent on the way in; the verdict hands it back so the adapter can roll it back.
        $this->assertSame($id, $verdict->reservedApprovalId);
    }

    /**
     * One pipeline over the real Woo catalog and rules. The identity token is
     * what the approval subject and the cap memo key on.
     */
    private function pipeline(?FakeApprovalStore $approvals = null, ?InMemoryAuditSink $sink = null): VerdictPipeline
    {
        $catalog = new VerbCatalog();
        $contributions = WooIntegration::register($catalog, new IdentityChain(), null);

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: [self::TOKEN]),
        ]));

        return new VerdictPipeline(
            new Gate(new TierClassifier($catalog, $contributions['elevationRules'])),
            new DecisionRecorder($sink ?? new InMemoryAuditSink(), $approvals),
            $approvals,
        );
    }

    private function fulfillmentBot(): Pack
    {
        foreach (WooPacks::all() as $pack) {
            if ($pack->name === 'fulfillment-bot') {
                return $pack;
            }
        }

        self::fail('fulfillment-bot preset not found');
    }

    /**
     * The shipped caps on a pack that DOES gate tier 2 on approval — the
     * shape a site clones when it wants a human on every elevated transition.
     */
    private function approvalGatedFulfillmentBot(): Pack
    {
        $bot = $this->fulfillmentBot();

        return new Pack(
            name: 'fulfillment-bot-gated',
            allow: $bot->allow,
            approvalByClass: ['tier2' => true],
            argumentCaps: $bot->argumentCaps,
        );
    }
}
