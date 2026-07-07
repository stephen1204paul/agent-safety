<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use WP_Error;

/**
 * Exercises the governed-namespace gate behaviour (SPEC seam 6): {@see
 * AbilityPermissionGate::wrap()} must be a complete no-op for any ability name
 * outside the injected namespace list, and an inert no-op for EVERYTHING when
 * that list is empty (a site with no integration active).
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

    private function gate(array $governedNamespaces): AbilityPermissionGate
    {
        return new AbilityPermissionGate(
            new Gate(),
            new PackResolver(),
            new DecisionRecorder(),
            null,
            $governedNamespaces,
        );
    }

    /** A gate wired so 'woocommerce/orders-list' resolves to $pack (backlog #16 rate-limit tests). */
    private function gateWithPack(Pack $pack): AbilityPermissionGate
    {
        $catalog = new VerbCatalog();
        $catalog->register(['woocommerce/orders-list' => Tier::Reversible]);
        $gate = new Gate(new TierClassifier($catalog));

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['test:token' => $pack->name];

        return new AbilityPermissionGate($gate, new PackResolver([$pack]), new DecisionRecorder(), null, ['woocommerce/']);
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

    public function testRateLimitAllowsCallsUnderThePackCap(): void
    {
        $pack = new Pack(name: 'capped', allow: ['woocommerce/*'], limits: ['calls_per_minute' => 2]);
        $gate = $this->gateWithPack($pack);
        $callback = $gate->wrap(['permission_callback' => static fn () => true], 'woocommerce/orders-list')['permission_callback'];

        // Distinct args on purpose: identical (verb, args) within one request is
        // memoized as the host re-checking the SAME call, not a new call.
        $this->assertTrue($callback(['call' => 1]));
        $this->assertTrue($callback(['call' => 2]));
    }

    public function testRateLimitBlocksCallsBeyondThePackCap(): void
    {
        $pack = new Pack(name: 'capped', allow: ['woocommerce/*'], limits: ['calls_per_minute' => 1]);
        $gate = $this->gateWithPack($pack);
        $callback = $gate->wrap(['permission_callback' => static fn () => true], 'woocommerce/orders-list')['permission_callback'];

        $this->assertTrue($callback(['call' => 1]));

        $second = $callback(['call' => 2]);
        $this->assertInstanceOf(WP_Error::class, $second);
        $this->assertSame('agent_safety_denied', $second->get_error_code());
        $this->assertStringContainsString('rate_limited_calls_per_minute', $second->get_error_message());
    }

    public function testUnlimitedPackIsNeverRateLimited(): void
    {
        $pack = new Pack(name: 'unlimited', allow: ['woocommerce/*']);
        $gate = $this->gateWithPack($pack);
        $callback = $gate->wrap(['permission_callback' => static fn () => true], 'woocommerce/orders-list')['permission_callback'];

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($callback([]));
        }
    }

    public function testUngovernedNamespaceIsNeverRateLimitedEitherWithACappedPack(): void
    {
        // wrap() is a no-op for an ungoverned ability -> the ORIGINAL callback
        // runs untouched, so the pack's cap (however small) never applies to it.
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $catalog = new VerbCatalog();
        $catalog->register(['core/something-else' => Tier::Reversible]);
        $gate = new AbilityPermissionGate(new Gate(new TierClassifier($catalog)), new PackResolver([$pack]), new DecisionRecorder(), null, ['woocommerce/']);

        $original = static fn () => true;
        $wrapped = $gate->wrap(['permission_callback' => $original], 'core/something-else');

        $this->assertSame($original, $wrapped['permission_callback']);
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
            new Gate(new TierClassifier($catalog)),
            new PackResolver([$boundPack]),
            new DecisionRecorder(),
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
}
