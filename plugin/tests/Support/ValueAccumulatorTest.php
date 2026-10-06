<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\ValueAccumulator;

/**
 * Exercises the fixed-window day-bucket storage itself, independent of the
 * cap arithmetic ({@see \Specflux\AgentSafety\Tests\Packs\ArgumentCapPolicyTest}
 * covers that in core) -- the same division of labour {@see
 * \Specflux\AgentSafety\Plugin\Tests\Support\RateCounterTest} exercises for
 * RateCounter. Time is frozen/advanced via $GLOBALS['wpas_test_time'], which
 * ValueAccumulator's bare time() call resolves to in this test run (see
 * tests/stubs/wpas-clock.php).
 */
final class ValueAccumulatorTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        \CounterTableWpdb::install();
        $GLOBALS['wpas_test_time'] = 1_700_000_000; // arbitrary fixed instant
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpas_test_time']);
    }

    public function testTotalsForAnUntouchedCapIsZero(): void
    {
        $accumulator = new ValueAccumulator();

        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
    }

    public function testReserveThenTotalsForRoundTrips(): void
    {
        $accumulator = new ValueAccumulator();

        $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 100.0]);
        $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 50.5]);

        // The table hands values back as strings -- confirm they still read as floats.
        $this->assertSame(['refund_total' => 150.5], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
    }

    public function testDifferentPackTokenCapTuplesDoNotShareBuckets(): void
    {
        $accumulator = new ValueAccumulator();
        $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 100.0]);

        $this->assertSame(['refund_total' => 100.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-b', 'token-1', ['refund_total']));
        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-a', 'token-2', ['refund_total']));
        $this->assertSame(['other_cap' => 0.0], $accumulator->totalsFor('pack-a', 'token-1', ['other_cap']));
    }

    public function testDayRolloverResetsTotalsToZero(): void
    {
        $accumulator = new ValueAccumulator();
        $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 500.0]);
        $this->assertSame(['refund_total' => 500.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));

        $GLOBALS['wpas_test_time'] += 86400;

        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
    }

    public function testReserveReturnsTheTotalsIncludingTheReservation(): void
    {
        $accumulator = new ValueAccumulator();

        $this->assertSame(['refund_total' => 100.0], $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 100.0]));
        $this->assertSame(['refund_total' => 150.5], $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 50.5]));
    }

    public function testReleaseGivesTheReservedAmountsBack(): void
    {
        $accumulator = new ValueAccumulator();
        $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 100.0]);

        $accumulator->reserve('pack-a', 'token-1', ['refund_total' => 40.0, 'other_cap' => 7.0]);
        $accumulator->release('pack-a', 'token-1', ['refund_total' => 40.0, 'other_cap' => 7.0]);

        $this->assertSame(
            ['refund_total' => 100.0, 'other_cap' => 0.0],
            $accumulator->totalsFor('pack-a', 'token-1', ['refund_total', 'other_cap']),
        );
    }
}
