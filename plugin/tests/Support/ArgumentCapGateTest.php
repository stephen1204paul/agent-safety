<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\ArgumentCapGate;
use Specflux\AgentSafety\Plugin\Support\ValueAccumulator;

/**
 * Glues {@see \Specflux\AgentSafety\Packs\ArgumentCapPolicy} (exhaustively
 * covered in core) to {@see ValueAccumulator} storage -- the argument-aware
 * sibling of {@see RateLimitGateTest}. Distinct simulated calls pass distinct
 * args on purpose except where a test is specifically about memoized
 * re-checks of the SAME call, per the same convention RateLimitGateTest
 * documents.
 */
final class ArgumentCapGateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = 1_700_000_000;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        unset($GLOBALS['wpas_test_time']);
    }

    public function testPackWithoutCapsAllowsAndNeverTouchesAnyTransient(): void
    {
        $pack = new Pack(name: 'p', allow: ['*']);
        $gate = new ArgumentCapGate();

        $check = $gate->check($pack, 'token-1', 'orders/refund', ['amount' => 999]);

        $this->assertTrue($check->allowed);
        $this->assertSame([], $GLOBALS['wpas_test_transients']);
    }

    public function testPackWithCapsNoneMatchingTheVerbAllowsAndNeverTouchesStorage(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/refund', 'amount', maxPerCall: 100.0);
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: [$cap]);
        $gate = new ArgumentCapGate();

        $check = $gate->check($pack, 'token-1', 'orders/other', ['amount' => 999]);

        $this->assertTrue($check->allowed);
        $this->assertSame([], $GLOBALS['wpas_test_transients']);
    }

    public function testAnAdmittedCallAccumulatesAndTheSameCallReCheckedIsMemoized(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 1000.0);
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: [$cap]);
        $accumulator = new ValueAccumulator();
        $gate = new ArgumentCapGate($accumulator);
        $args = ['amount' => 100];

        $first = $gate->check($pack, 'token-1', 'orders/refund', $args);
        $second = $gate->check($pack, 'token-1', 'orders/refund', $args);

        $this->assertTrue($first->allowed);
        $this->assertSame($first, $second); // same verdict instance -> proves the memo, not just equal values
        // Accumulated exactly once -- the re-check did not add a second 100.
        $this->assertSame(['refund_total' => 100.0], $accumulator->totalsFor('p', 'token-1', ['refund_total']));
    }

    public function testADeniedCallDoesNotAccumulate(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 50.0, maxTotalPerDay: 1000.0);
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: [$cap]);
        $accumulator = new ValueAccumulator();
        $gate = new ArgumentCapGate($accumulator);

        $check = $gate->check($pack, 'token-1', 'orders/refund', ['amount' => 100]);

        $this->assertFalse($check->allowed);
        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('p', 'token-1', ['refund_total']));
    }

    public function testARequireApprovalVerdictDoesNotAccumulate(): void
    {
        $cap = new ArgumentCap('big_edit', 'orders/*', 'amount', approvalAbove: 100.0, maxTotalPerDay: 1000.0);
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: [$cap]);
        $accumulator = new ValueAccumulator();
        $gate = new ArgumentCapGate($accumulator);

        $check = $gate->check($pack, 'token-1', 'orders/refund', ['amount' => 200]);

        $this->assertTrue($check->requiresApproval);
        $this->assertFalse($check->allowed);
        $this->assertSame(['big_edit' => 0.0], $accumulator->totalsFor('p', 'token-1', ['big_edit']));
    }

    public function testHasValidApprovalReCheckOfTheSameCallReEvaluatesAndAccumulatesOnce(): void
    {
        $cap = new ArgumentCap('big_edit', 'orders/*', 'amount', approvalAbove: 100.0, maxTotalPerDay: 1000.0);
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: [$cap]);
        $accumulator = new ValueAccumulator();
        $gate = new ArgumentCapGate($accumulator);
        $args = ['amount' => 200];

        $pending = $gate->check($pack, 'token-1', 'orders/refund', $args, false);
        $this->assertTrue($pending->requiresApproval);

        // Different approval flag -> different memo key -> a genuine
        // re-evaluation, not the pending verdict replayed.
        $approved = $gate->check($pack, 'token-1', 'orders/refund', $args, true);
        $this->assertNotSame($pending, $approved);
        $this->assertTrue($approved->allowed);

        // A further re-check with the SAME approval flag is now memoized.
        $approvedAgain = $gate->check($pack, 'token-1', 'orders/refund', $args, true);
        $this->assertSame($approved, $approvedAgain);

        // Accumulated exactly once, from the single admitted evaluation.
        $this->assertSame(['big_edit' => 200.0], $accumulator->totalsFor('p', 'token-1', ['big_edit']));
    }

    public function testNullTokenSharesOneAnonymousBucket(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 150.0);
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: [$cap]);
        $accumulator = new ValueAccumulator();
        $gate = new ArgumentCapGate($accumulator);

        $first = $gate->check($pack, null, 'orders/refund', ['amount' => 100]);
        $this->assertTrue($first->allowed);

        // Distinct args on purpose -- a genuinely new call. If null tokens
        // did NOT share a bucket this would still be well under the cap.
        $second = $gate->check($pack, null, 'orders/refund', ['amount' => 60]);

        $this->assertFalse($second->allowed);
        $this->assertSame('refund_total', $second->trippedCap);
        $this->assertSame('max_total_per_day', $second->constraint);
    }
}
