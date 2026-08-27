<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use WP_Error;

/**
 * {@see AbilityPermissionGate} is the CLAIM-mode ADAPTER of the shared
 * {@see VerdictPipeline}, so this file only tests the adapting:
 *
 *   - WHICH abilities get wrapped at all (the governed-namespace list) and that
 *     wrapping is otherwise a complete no-op;
 *   - that the ability's own permission_callback still runs FIRST and its
 *     denial wins (least privilege — we only ever add restrictions);
 *   - that the pack is resolved inside the callback, at call time;
 *   - that the registration's `meta.annotations` reach the pipeline as Hints;
 *   - and the one piece of state this adapter owns itself: remembering the
 *     reserved approval id so the action's execution can finalize or roll it
 *     back.
 *
 * Every gating BEHAVIOUR behind the verdict — rate caps, argument caps, shadow
 * mode, the approval flow, re-entrancy, what a hint does to a decision — is
 * proven once, in both modes, in
 * {@see \Specflux\AgentSafety\Plugin\Tests\Verdict\VerdictPipelineTest}
 * (and hint PARSING in
 * {@see \Specflux\AgentSafety\Plugin\Tests\Verdict\HintsTest}).
 */
final class AbilityPermissionGateTest extends TestCase
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
    }

    /** @param list<string> $governedNamespaces */
    private function gate(array $governedNamespaces): AbilityPermissionGate
    {
        return new AbilityPermissionGate(
            new VerdictPipeline(new Gate(), new DecisionRecorder()),
            new PackResolver(),
            null,
            $governedNamespaces,
        );
    }

    /**
     * A gate wired so 'woocommerce/orders-list' resolves to $pack, with the
     * audit sink + approval store the reservation scenarios need to observe.
     */
    private function gateWithPackAndRecording(
        Pack $pack,
        InMemoryAuditSink $sink,
        FakeApprovalStore $approvals,
    ): AbilityPermissionGate {
        $catalog = new VerbCatalog();
        $catalog->register(['woocommerce/orders-list' => Tier::Reversible]);
        $gate = new Gate(new TierClassifier($catalog));

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['test:token' => $pack->name];

        return new AbilityPermissionGate(
            new VerdictPipeline($gate, new DecisionRecorder($sink, $approvals), $approvals),
            new PackResolver([$pack]),
            $approvals,
            ['woocommerce/'],
        );
    }

    public function testUngovernedNamespaceLeavesArgsAndCallbackUntouched(): void
    {
        $gate = $this->gate(['woocommerce/']);
        $original = static fn () => true;
        $args = ['permission_callback' => $original];

        $wrapped = $gate->wrap($args, 'core/something-else');

        $this->assertSame($args, $wrapped);
        $this->assertSame($original, $wrapped['permission_callback']);
    }

    public function testGovernedNamespaceReplacesThePermissionCallback(): void
    {
        $gate = $this->gate(['woocommerce/']);
        $original = static fn () => true;
        $args = ['permission_callback' => $original];

        $wrapped = $gate->wrap($args, 'woocommerce/orders-list');

        $this->assertNotSame($original, $wrapped['permission_callback']);
        $this->assertIsCallable($wrapped['permission_callback']);
    }

    public function testEmptyGovernedNamespacesIsInertForEveryAbility(): void
    {
        $gate = $this->gate([]);
        $original = static fn () => true;
        $args = ['permission_callback' => $original];

        $wrapped = $gate->wrap($args, 'woocommerce/orders-list');

        $this->assertSame($args, $wrapped);
    }

    public function testMultipleGovernedNamespacesEachApply(): void
    {
        $gate = $this->gate(['woocommerce/', 'custom-integration/']);
        $original = static fn () => true;

        $wrappedWoo = $gate->wrap(['permission_callback' => $original], 'woocommerce/orders-list');
        $wrappedCustom = $gate->wrap(['permission_callback' => $original], 'custom-integration/do-thing');
        $wrappedOther = $gate->wrap(['permission_callback' => $original], 'other/thing');

        $this->assertNotSame($original, $wrappedWoo['permission_callback']);
        $this->assertNotSame($original, $wrappedCustom['permission_callback']);
        $this->assertSame($original, $wrappedOther['permission_callback']);
    }

    public function testUngovernedNamespaceIsNeverRateLimitedEitherWithACappedPack(): void
    {
        // wrap() is a no-op for an ungoverned ability -> the ORIGINAL callback
        // runs untouched, so the pack's cap (however small) never applies to it.
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $catalog = new VerbCatalog();
        $catalog->register(['core/something-else' => Tier::Reversible]);
        $gate = new AbilityPermissionGate(
            new VerdictPipeline(new Gate(new TierClassifier($catalog)), new DecisionRecorder()),
            new PackResolver([$pack]),
            null,
            ['woocommerce/'],
        );

        $original = static fn () => true;
        $wrapped = $gate->wrap(['permission_callback' => $original], 'core/something-else');

        $this->assertSame($original, $wrapped['permission_callback']);
    }

    public function testTheAbilitysOwnPermissionDenialWinsAndTheGateNeverRelaxesIt(): void
    {
        // Least privilege: we only ever ADD restrictions. The wrapped callback
        // runs the ability's own check first and returns its refusal verbatim,
        // even though this pack would have allowed the verb.
        $pack = new Pack(name: 'owner', allow: ['*']);
        $gate = $this->gateWithPackAndRecording($pack, new InMemoryAuditSink(), new FakeApprovalStore());
        $refusal = new WP_Error('woocommerce_rest_cannot_view', 'Sorry, you cannot list resources.');
        $callback = $gate->wrap(['permission_callback' => static fn () => $refusal], 'woocommerce/orders-list')['permission_callback'];

        $this->assertSame($refusal, $callback(['id' => 1]));
    }

    /**
     * REGRESSION (live smoke test, 2026-07-07): wrap() runs at ability-registration
     * time (`init`), but application-password identity only exists after the REST
     * server's authentication phase — strictly later. The pack must therefore be
     * resolved INSIDE the permission callback at call time. This test wraps first
     * (no identity, no binding yet — registration-time reality), establishes the
     * identity and binding afterwards, and asserts the bound pack — not the
     * fail-closed default — is what the closure enforces.
     */
    public function testPackIsResolvedAtCallTimeNotRegistrationTime(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['woocommerce/orders-list' => Tier::Reversible]);
        $boundPack = new Pack(name: 'late-bound', allow: ['woocommerce/*']);
        $gate = new AbilityPermissionGate(
            new VerdictPipeline(new Gate(new TierClassifier($catalog)), new DecisionRecorder()),
            new PackResolver([$boundPack]),
            null,
            ['woocommerce/'],
        );

        // Registration time: no identity resolved yet, no binding stored.
        $wrapped = $gate->wrap(['permission_callback' => static fn () => true], 'woocommerce/orders-list');

        // Authentication happens AFTER registration (as in a real REST request).
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['app:late-uuid']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:late-uuid' => 'late-bound'];

        // Under the bound pack this verb is allowed; under the stale default
        // pack (allow: []) it would come back as a WP_Error denial.
        $this->assertTrue(($wrapped['permission_callback'])([]));
    }

    public function testTheRegistrationsDestructiveAnnotationReachesThePipeline(): void
    {
        // Proves Hints::fromAbilityArgs() is wired into the claim-mode judge():
        // the SAME verb and pack allow the call outright without the annotation,
        // and demand approval with it. (What the elevation itself does is
        // proven in VerdictPipelineTest.)
        $pack = new Pack(name: 'support', allow: ['woocommerce/*'], approvalByClass: ['tier2' => true]);
        $approvals = new FakeApprovalStore();
        $gate = $this->gateWithPackAndRecording($pack, new InMemoryAuditSink(), $approvals);

        $plain = $gate->wrap(['permission_callback' => static fn () => true], 'woocommerce/orders-list')['permission_callback'];
        $this->assertTrue($plain(['id' => 1]));

        $annotated = $gate->wrap([
            'permission_callback' => static fn () => true,
            'meta' => ['annotations' => ['destructive' => true]],
        ], 'woocommerce/orders-list')['permission_callback'];

        $result = $annotated(['id' => 2]);
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('approval_required', $result->get_error_code());
    }

    /**
     * Drives a governed call all the way to a reserved grant, which is the
     * precondition for both onExecuted() cases below. Asserting the reservation
     * here is also what proves the adapter REMEMBERED the verdict's
     * reservedApprovalId — nothing else in this file could observe it.
     *
     * @return array{AbilityPermissionGate, FakeApprovalStore, string, array<string, mixed>}
     */
    private function reservedGrant(): array
    {
        $cap = new ArgumentCap('big_edit', 'woocommerce/*', 'amount', approvalAbove: 100.0);
        $pack = new Pack(name: 'approval-args', allow: ['woocommerce/*'], argumentCaps: [$cap]);
        $approvals = new FakeApprovalStore();
        $args = ['amount' => 500];
        $id = $approvals->seedApproved('woocommerce/orders-list', ApprovalBinding::hash('woocommerce/orders-list', $args), 'test:token');
        $gate = $this->gateWithPackAndRecording($pack, new InMemoryAuditSink(), $approvals);
        $callback = $gate->wrap(['permission_callback' => static fn () => true], 'woocommerce/orders-list')['permission_callback'];
        $this->assertTrue($callback($args));
        $this->assertSame('in_flight', $approvals->rows[$id]['status']);

        return [$gate, $approvals, $id, $args];
    }

    public function testOnExecutedFinalizesTheGrantWhenTheResultCarriesCodeAndMessageAsData(): void
    {
        [$gate, $approvals, $id, $args] = $this->reservedGrant();

        $gate->onExecuted('woocommerce/orders-list', $args, ['code' => 'ORDER-1042', 'message' => 'Shipped', 'id' => 1042]);

        $this->assertSame('consumed', $approvals->rows[$id]['status'], 'a real success must spend the grant, never release it for reuse');
    }

    public function testOnExecutedRollsBackTheGrantOnARestErrorPayload(): void
    {
        [$gate, $approvals, $id, $args] = $this->reservedGrant();

        $gate->onExecuted('woocommerce/orders-list', $args, ['code' => 'rest_invalid', 'message' => 'bad', 'data' => ['status' => 400]]);

        $this->assertSame('approved', $approvals->rows[$id]['status']);
    }
}
