<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Hooks\PreToolCallGate;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Integrations\Woo\VerbMapper;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeMcpTool;
use WP_Error;

/**
 * Exercises {@see PreToolCallGate}'s two backlog features together: the D3
 * destructive/readOnly annotation handling (backlog #15) and the pack
 * rate/quota caps (backlog #16). "demo-write" (-> ability id "demo/write") is
 * a Tier::SideEffecting verb an unrestricted-but-approval-gated pack would
 * otherwise allow outright, which is exactly the case a destructiveHint must
 * be able to escalate.
 */
final class PreToolCallGateTest extends TestCase
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

    /** @param array<string, Tier> $verbToTier */
    private function gateFor(array $verbToTier, Pack $pack, ?RateLimitGate $rateLimits = null): PreToolCallGate
    {
        $catalog = new VerbCatalog();
        $catalog->register($verbToTier);
        $gate = new Gate(new TierClassifier($catalog));

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['test:token' => $pack->name];
        $packs = new PackResolver([$pack]);

        return $rateLimits === null
            ? new PreToolCallGate($gate, new VerbMapper(), $packs, new DecisionRecorder())
            : new PreToolCallGate($gate, new VerbMapper(), $packs, new DecisionRecorder(), $rateLimits);
    }

    private function approvalGatedPack(): Pack
    {
        return new Pack(name: 'support', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
    }

    public function testDestructiveHintedToolBelowApprovalTierIsElevatedToApprovalRequired(): void
    {
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $this->approvalGatedPack());

        // Without the hint this verb is Tier 1 and this pack allows it outright.
        $plainResult = $gate->handle([], 'demo-write');
        $this->assertSame([], $plainResult);

        // With destructiveHint === true, the pack now demands approval for it.
        $result = $gate->handle([], 'demo-write', FakeMcpTool::withHints(destructiveHint: true));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('approval_required', $result->get_error_code());
        $this->assertSame(Tier::Irreversible->value, $result->get_error_data()['tier'] ?? null);
    }

    public function testDestructiveHintOnAPackThatHardDeniesTier2IsDeniedNotJustGatedOnApproval(): void
    {
        $pack = new Pack(name: 'walled', allow: ['demo/*'], denyClass: ['tier2']);
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $pack);

        $result = $gate->handle([], 'demo-write', FakeMcpTool::withHints(destructiveHint: true));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('agent_safety_denied', $result->get_error_code());
    }

    public function testDestructiveHintOnAnAlreadyIrreversibleVerbChangesNothing(): void
    {
        // Already at the top tier -> elevateForDestructiveHint's early-return
        // (tier === Irreversible) leaves the ApprovalRequired verdict as-is.
        $gate = $this->gateFor(['demo/refund' => Tier::Irreversible], $this->approvalGatedPack());

        $withoutHint = $gate->handle([], 'demo-refund');
        $withHint = $gate->handle([], 'demo-refund', FakeMcpTool::withHints(destructiveHint: true));

        $this->assertInstanceOf(WP_Error::class, $withoutHint);
        $this->assertInstanceOf(WP_Error::class, $withHint);
        $this->assertSame($withoutHint->get_error_code(), $withHint->get_error_code());
    }

    public function testReadOnlyHintNeverBypassesAnApprovalRequirement(): void
    {
        // OUR catalog already classifies this as Tier 2; a self-reported
        // readOnlyHint must not let it slip through as allowed (D3).
        $gate = $this->gateFor(['demo/refund' => Tier::Irreversible], $this->approvalGatedPack());

        $result = $gate->handle([], 'demo-refund', FakeMcpTool::withHints(readOnlyHint: true));

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    public function testReadOnlyHintedWriteVerbFailsClosedAsALyingAnnotation(): void
    {
        // This is the pre-existing readonly-but-writes mismatch check (D3) —
        // now reachable because the accessor is wired instead of the old
        // hardcoded stub. A verb OUR catalog says writes, self-reported as
        // read-only, must be denied outright.
        $pack = new Pack(name: 'owner', allow: ['*']);
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $pack);

        $result = $gate->handle([], 'demo-write', FakeMcpTool::withHints(readOnlyHint: true));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('agent_safety_denied', $result->get_error_code());
    }

    public function testNoMcpToolArgumentBehavesAsNoHint(): void
    {
        $pack = new Pack(name: 'owner', allow: ['*']);
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $pack);

        $result = $gate->handle(['id' => 1], 'demo-write');

        $this->assertSame(['id' => 1], $result);
    }

    public function testMcpToolWithNullAnnotationsDtoBehavesAsNoHint(): void
    {
        $pack = new Pack(name: 'owner', allow: ['*']);
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $pack);

        $result = $gate->handle(['id' => 1], 'demo-write', FakeMcpTool::withHints());

        $this->assertSame(['id' => 1], $result);
    }

    public function testAnnotationsObjectMissingTheHintMethodsBehavesAsNoHint(): void
    {
        $pack = new Pack(name: 'owner', allow: ['*']);
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $pack);

        // An "annotations" object of some foreign/older shape with neither hint
        // accessor at all -> duck-typing must fall back to "no hint", not fatal.
        $result = $gate->handle(['id' => 1], 'demo-write', FakeMcpTool::withAnnotations(new \stdClass()));

        $this->assertSame(['id' => 1], $result);
    }

    public function testForeignMcpToolObjectNeverFatals(): void
    {
        $pack = new Pack(name: 'owner', allow: ['*']);
        $gate = $this->gateFor(['demo/write' => Tier::SideEffecting], $pack);

        // A completely unrelated object standing in for a different adapter
        // version's $mcp_tool shape -> every duck-typing hop must bail out safely.
        $result = $gate->handle(['id' => 1], 'demo-write', new \stdClass());

        $this->assertSame(['id' => 1], $result);
    }

    public function testRateLimitBlocksCallsBeyondThePackCapAndDoesNotConsumeQuotaOnDenial(): void
    {
        $GLOBALS['wpas_test_time'] = 1_700_000_000;
        $pack = new Pack(name: 'owner', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = $this->gateFor(['demo/read' => Tier::Reversible], $pack, new RateLimitGate());

        $first = $gate->handle(['id' => 1], 'demo-read');
        $this->assertSame(['id' => 1], $first);

        $second = $gate->handle(['id' => 1], 'demo-read');
        $this->assertInstanceOf(WP_Error::class, $second);
        $this->assertSame('agent_safety_denied', $second->get_error_code());
        $this->assertStringContainsString('rate_limited_calls_per_minute', $second->get_error_message());

        // A third attempt is still blocked -- the denial itself did not free up
        // (or spend down further than) the same single slot.
        $third = $gate->handle(['id' => 1], 'demo-read');
        $this->assertInstanceOf(WP_Error::class, $third);
    }
}
