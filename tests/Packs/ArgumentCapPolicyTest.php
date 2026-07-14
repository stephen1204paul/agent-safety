<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\ArgumentCapPolicy;

/**
 * Exercises the pure argument-cap evaluator in isolation from the host's
 * storage ({@see \Specflux\AgentSafety\Plugin\Support\ArgumentCapGate} covers
 * the glue in the plugin suite). Mirrors {@see LimitPolicyTest}'s house
 * style: no mocking, one behaviour per test, boundary values named in the
 * method name.
 */
final class ArgumentCapPolicyTest extends TestCase
{
    private ArgumentCapPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ArgumentCapPolicy();
    }

    public function testAllowsWhenNoCapMatchesTheVerb(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/refund', 'amount', maxPerCall: 100.0);

        $check = $this->policy->evaluate([$cap], 'orders/other', ['amount' => 5000], []);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->trippedCap);
    }

    public function testMaxPerCallAllowsAtExactlyTheCap(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 500.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 500], []);

        $this->assertTrue($check->allowed);
    }

    public function testMaxPerCallDeniesAboveTheCap(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 500.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 500.01], []);

        $this->assertFalse($check->allowed);
        $this->assertFalse($check->requiresApproval);
        $this->assertSame('refund_total', $check->trippedCap);
        $this->assertSame('max_per_call', $check->constraint);
    }

    public function testMaxTotalPerDayAllowsWhenItLandsExactlyOnTheCap(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 1000.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 100], ['refund_total' => 900.0]);

        $this->assertTrue($check->allowed);
    }

    public function testMaxTotalPerDayDeniesWhenItWouldCrossTheCap(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 1000.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 100.01], ['refund_total' => 900.0]);

        $this->assertFalse($check->allowed);
        $this->assertSame('refund_total', $check->trippedCap);
        $this->assertSame('max_total_per_day', $check->constraint);
    }

    public function testMaxTotalPerDayTreatsAMissingDayTotalAsZero(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 1000.0);

        // No 'refund_total' key at all in $dayTotals -> treated as 0.0, so
        // landing exactly on the cap is still allowed.
        $allowed = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 1000], []);
        $denied = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 1000.01], []);

        $this->assertTrue($allowed->allowed);
        $this->assertFalse($denied->allowed);
        $this->assertSame('max_total_per_day', $denied->constraint);
    }

    public function testApprovalAboveAllowsAtExactlyTheThreshold(): void
    {
        $cap = new ArgumentCap('big_edit', 'orders/*', 'amount', approvalAbove: 500.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 500], []);

        $this->assertTrue($check->allowed);
        $this->assertFalse($check->requiresApproval);
    }

    public function testApprovalAboveRequiresApprovalAboveTheThreshold(): void
    {
        $cap = new ArgumentCap('big_edit', 'orders/*', 'amount', approvalAbove: 500.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 500.01], []);

        $this->assertFalse($check->allowed);
        $this->assertTrue($check->requiresApproval);
        $this->assertSame('big_edit', $check->trippedCap);
        $this->assertSame('approval_above', $check->constraint);
    }

    public function testHasValidApprovalSkipsApprovalAboveAndAllows(): void
    {
        $cap = new ArgumentCap('big_edit', 'orders/*', 'amount', approvalAbove: 100.0);

        $check = $this->policy->evaluate(
            [$cap],
            'orders/refund',
            ['amount' => 5000],
            [],
            hasValidApproval: true,
        );

        $this->assertTrue($check->allowed);
    }

    public function testHasValidApprovalStillDeniesAMaxPerCallTrip(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 100.0);

        $check = $this->policy->evaluate(
            [$cap],
            'orders/refund',
            ['amount' => 200],
            [],
            hasValidApproval: true,
        );

        $this->assertFalse($check->allowed);
        $this->assertSame('max_per_call', $check->constraint);
    }

    public function testHasValidApprovalStillDeniesAMaxTotalPerDayTrip(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 100.0);

        $check = $this->policy->evaluate(
            [$cap],
            'orders/refund',
            ['amount' => 200],
            [],
            hasValidApproval: true,
        );

        $this->assertFalse($check->allowed);
        $this->assertSame('max_total_per_day', $check->constraint);
    }

    public function testDenyBeatsAnEarlierApprovalTrip(): void
    {
        // Cap A trips the approval threshold first (scan continues past it);
        // cap B, declared after, trips a hard deny -> the verdict must be B's
        // deny, not A's require-approval.
        $capA = new ArgumentCap('cap_a', 'orders/*', 'amount_a', approvalAbove: 100.0);
        $capB = new ArgumentCap('cap_b', 'orders/*', 'amount_b', maxPerCall: 50.0);

        $check = $this->policy->evaluate(
            [$capA, $capB],
            'orders/refund',
            ['amount_a' => 200, 'amount_b' => 999],
            [],
        );

        $this->assertFalse($check->allowed);
        $this->assertFalse($check->requiresApproval);
        $this->assertSame('cap_b', $check->trippedCap);
        $this->assertSame('max_per_call', $check->constraint);
    }

    public function testUnreadableArgumentDeniesWhenTheArgIsMissing(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 100.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', [], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('refund_total', $check->trippedCap);
        $this->assertSame('unreadable_argument', $check->constraint);
    }

    public function testUnreadableArgumentDeniesANonNumericString(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 100.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => 'a lot'], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('unreadable_argument', $check->constraint);
    }

    public function testUnreadableArgumentDeniesAnArrayWhereAValueConstraintApplies(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 100.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => [1, 2, 3]], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('unreadable_argument', $check->constraint);
    }

    public function testNumericStringValuesAreAccepted(): void
    {
        // WooCommerce sends amounts as strings.
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 100.0);

        $allowed = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => '42.50'], []);
        $this->assertTrue($allowed->allowed);

        // A tighter cap proves the string was actually parsed to 42.50, not
        // merely treated as "present" -- 40 < 42.50 must still trip.
        $tighterCap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 40.0);
        $denied = $this->policy->evaluate([$tighterCap], 'orders/refund', ['amount' => '42.50'], []);
        $this->assertFalse($denied->allowed);
        $this->assertSame('max_per_call', $denied->constraint);
    }

    public function testMagnitudeRuleDeniesANegativeValueAboveTheCapByAbsoluteValue(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 500.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['amount' => -600], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('max_per_call', $check->constraint);
    }

    public function testMagnitudeRuleAccumulatesANegativeValueAsItsAbsoluteValue(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 1000.0);

        $amounts = $this->policy->accumulableAmounts([$cap], 'orders/refund', ['amount' => -400]);

        $this->assertSame(['refund_total' => 400.0], $amounts);
    }

    public function testMaxItemsPerCallDeniesAboveTheCount(): void
    {
        $cap = new ArgumentCap('bulk_items', 'orders/*', 'items', maxItemsPerCall: 25);

        $check = $this->policy->evaluate([$cap], 'orders/bulk-update', ['items' => range(1, 26)], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('bulk_items', $check->trippedCap);
        $this->assertSame('max_items_per_call', $check->constraint);
    }

    public function testMaxItemsPerCallAllowsAtExactlyTheCount(): void
    {
        $cap = new ArgumentCap('bulk_items', 'orders/*', 'items', maxItemsPerCall: 25);

        $check = $this->policy->evaluate([$cap], 'orders/bulk-update', ['items' => range(1, 25)], []);

        $this->assertTrue($check->allowed);
    }

    public function testMaxItemsPerCallDeniesANonArrayValueAsUnreadable(): void
    {
        $cap = new ArgumentCap('bulk_items', 'orders/*', 'items', maxItemsPerCall: 25);

        $check = $this->policy->evaluate([$cap], 'orders/bulk-update', ['items' => 'not-a-list'], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('unreadable_argument', $check->constraint);
    }

    public function testDotPathReadsANestedArgument(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'refund.total', maxPerCall: 500.0);

        // If the dot-path were NOT followed, resolve() would hand back the
        // whole ['total' => 600] array, which is_numeric() rejects as
        // unreadable rather than as a max_per_call trip -- so seeing
        // max_per_call here proves the nested value was actually read.
        $check = $this->policy->evaluate([$cap], 'orders/refund', ['refund' => ['total' => 600]], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('max_per_call', $check->constraint);
    }

    public function testDotPathWithAMissingMiddleSegmentDeniesAsUnreadable(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'refund.total', maxPerCall: 500.0);

        $check = $this->policy->evaluate([$cap], 'orders/refund', ['refund' => ['currency' => 'USD']], []);

        $this->assertFalse($check->allowed);
        $this->assertSame('unreadable_argument', $check->constraint);
    }

    public function testFirstHardDenialInDeclarationOrderWins(): void
    {
        $capA = new ArgumentCap('cap_a', 'orders/*', 'a', maxPerCall: 10.0);
        $capB = new ArgumentCap('cap_b', 'orders/*', 'b', maxPerCall: 5.0);
        $args = ['a' => 50, 'b' => 50];

        $aFirst = $this->policy->evaluate([$capA, $capB], 'orders/refund', $args, []);
        $bFirst = $this->policy->evaluate([$capB, $capA], 'orders/refund', $args, []);

        $this->assertSame('cap_a', $aFirst->trippedCap);
        $this->assertSame('cap_b', $bFirst->trippedCap);
    }

    public function testAccumulableAmountsReturnsOnlyMatchingAccumulatingNumericReadableCapsKeyedById(): void
    {
        $matchesAndAccumulates = new ArgumentCap('refund_total', 'orders/*', 'amount', maxTotalPerDay: 1000.0);
        $doesNotMatchTheVerb = new ArgumentCap('other_verb', 'products/*', 'amount', maxTotalPerDay: 1000.0);
        $doesNotAccumulate = new ArgumentCap('per_call_only', 'orders/*', 'amount', maxPerCall: 1000.0);
        $unreadableValue = new ArgumentCap('unreadable', 'orders/*', 'missing', maxTotalPerDay: 1000.0);

        $amounts = $this->policy->accumulableAmounts(
            [$matchesAndAccumulates, $doesNotMatchTheVerb, $doesNotAccumulate, $unreadableValue],
            'orders/refund',
            ['amount' => -250],
        );

        $this->assertSame(['refund_total' => 250.0], $amounts);
    }
}
