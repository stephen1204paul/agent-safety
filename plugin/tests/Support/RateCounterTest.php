<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\RateCounter;

/**
 * Exercises the fixed-window bucketing itself (backlog #16), independent of
 * the allow/deny arithmetic ({@see \Specflux\AgentSafety\Packs\LimitPolicyTest}
 * covers that in core). Time is frozen/advanced via $GLOBALS['wpas_test_time'],
 * which Support\RateCounter's bare time() call resolves to in this test run
 * (see tests/stubs/wpas-clock.php).
 */
final class RateCounterTest extends TestCase
{
    protected function setUp(): void
    {
        \CounterTableWpdb::install();
        $GLOBALS['wpas_test_time'] = 1_700_000_000; // arbitrary fixed instant
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpas_test_time']);
    }

    public function testFreshCounterStartsAtZero(): void
    {
        $counter = new RateCounter();

        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-a', 'token-1'));
    }

    public function testReserveIncreasesBothWindows(): void
    {
        $counter = new RateCounter();

        $counter->reserve('pack-a', 'token-1');
        $counter->reserve('pack-a', 'token-1');

        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->countsFor('pack-a', 'token-1'));
    }

    public function testReserveReturnsTheCountsIncludingTheReservation(): void
    {
        $counter = new RateCounter();

        $this->assertSame(['minute' => 1, 'hour' => 1], $counter->reserve('pack-a', 'token-1'));
        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->reserve('pack-a', 'token-1'));
    }

    public function testReleaseUndoesOneReservationOnBothWindows(): void
    {
        $counter = new RateCounter();
        $counter->reserve('pack-a', 'token-1');
        $before = $counter->countsFor('pack-a', 'token-1');

        $counter->reserve('pack-a', 'token-1');
        $counter->release('pack-a', 'token-1');

        $this->assertSame($before, $counter->countsFor('pack-a', 'token-1'));
    }

    public function testDifferentTokensUnderTheSamePackHaveIndependentCounters(): void
    {
        $counter = new RateCounter();

        $counter->reserve('pack-a', 'token-1');

        $this->assertSame(['minute' => 1, 'hour' => 1], $counter->countsFor('pack-a', 'token-1'));
        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-a', 'token-2'));
    }

    public function testDifferentPacksForTheSameTokenHaveIndependentCounters(): void
    {
        $counter = new RateCounter();

        $counter->reserve('pack-a', 'token-1');

        $this->assertSame(['minute' => 1, 'hour' => 1], $counter->countsFor('pack-a', 'token-1'));
        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-b', 'token-1'));
    }

    public function testMinuteBucketResetsOnANewMinuteButHourPersists(): void
    {
        $counter = new RateCounter();
        $counter->reserve('pack-a', 'token-1');
        $counter->reserve('pack-a', 'token-1');
        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->countsFor('pack-a', 'token-1'));

        // Cross into the next 60s bucket, but stay within the same hour.
        $GLOBALS['wpas_test_time'] += 60;

        $this->assertSame(['minute' => 0, 'hour' => 2], $counter->countsFor('pack-a', 'token-1'));

        $counter->reserve('pack-a', 'token-1');
        $this->assertSame(['minute' => 1, 'hour' => 3], $counter->countsFor('pack-a', 'token-1'));
    }

    public function testHourBucketAlsoResetsOnANewHour(): void
    {
        $counter = new RateCounter();
        $counter->reserve('pack-a', 'token-1');

        $GLOBALS['wpas_test_time'] += 3600;

        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-a', 'token-1'));
    }
}
