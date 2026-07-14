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
        $GLOBALS['wpas_test_time'] = 1_700_000_000; // arbitrary fixed instant
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        unset($GLOBALS['wpas_test_time']);
    }

    public function testTotalsForAnUntouchedCapIsZero(): void
    {
        $accumulator = new ValueAccumulator();

        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
    }

    public function testAccumulateThenTotalsForRoundTrips(): void
    {
        $accumulator = new ValueAccumulator();

        $accumulator->accumulate('pack-a', 'token-1', ['refund_total' => 100.0]);
        $accumulator->accumulate('pack-a', 'token-1', ['refund_total' => 50.5]);

        // Storage is a string under the hood (mirroring a real DB-backed
        // transient) -- confirm the round trip still reads back as a float.
        $key = array_key_first($GLOBALS['wpas_test_transients']);
        $this->assertIsString($GLOBALS['wpas_test_transients'][$key]['value']);
        $this->assertSame(['refund_total' => 150.5], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
    }

    public function testDifferentPackTokenCapTuplesDoNotShareBuckets(): void
    {
        $accumulator = new ValueAccumulator();
        $accumulator->accumulate('pack-a', 'token-1', ['refund_total' => 100.0]);

        $this->assertSame(['refund_total' => 100.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-b', 'token-1', ['refund_total']));
        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-a', 'token-2', ['refund_total']));
        $this->assertSame(['other_cap' => 0.0], $accumulator->totalsFor('pack-a', 'token-1', ['other_cap']));
    }

    public function testDayRolloverResetsTotalsToZero(): void
    {
        $accumulator = new ValueAccumulator();
        $accumulator->accumulate('pack-a', 'token-1', ['refund_total' => 500.0]);
        $this->assertSame(['refund_total' => 500.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));

        $GLOBALS['wpas_test_time'] += 86400;

        $this->assertSame(['refund_total' => 0.0], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
    }

    /**
     * REGRESSION-shaped (mirrors RateCounterTest's DB-backed-string case): a
     * numeric-string transient value -- exactly what a real wp_options text
     * column round trip leaves behind, seeded here directly rather than via
     * accumulate() -- must still be read as a float.
     */
    public function testANumericStringTransientSeededDirectlyIsReadAsFloat(): void
    {
        $accumulator = new ValueAccumulator();
        // Prime the bucket so we know its real (hashed) key, then overwrite
        // the raw stored value as a DB round-trip would leave it.
        $accumulator->accumulate('pack-a', 'token-1', ['refund_total' => 1.0]);
        $key = array_key_first($GLOBALS['wpas_test_transients']);
        $GLOBALS['wpas_test_transients'][$key]['value'] = '250.75';

        $this->assertSame(['refund_total' => 250.75], $accumulator->totalsFor('pack-a', 'token-1', ['refund_total']));
    }
}
