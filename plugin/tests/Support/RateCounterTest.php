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
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = 1_700_000_000; // arbitrary fixed instant
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        unset($GLOBALS['wpas_test_time']);
    }

    public function testFreshCounterStartsAtZero(): void
    {
        $counter = new RateCounter();

        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-a', 'token-1'));
    }

    public function testIncrementIncreasesBothWindows(): void
    {
        $counter = new RateCounter();

        $counter->increment('pack-a', 'token-1');
        $counter->increment('pack-a', 'token-1');

        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->countsFor('pack-a', 'token-1'));
    }

    /**
     * REGRESSION (live smoke test, 2026-07-07): DB-backed transients — the
     * WordPress default, no persistent object cache — round-trip through
     * wp_options text columns and come back as STRINGS. An is_int() read
     * check zeroed the counter on every fresh request, so caps never tripped
     * across requests. Simulate the DB round-trip by stringifying the stored
     * values and assert the counts survive.
     */
    public function testStringTransientValuesFromDbBackedSitesStillCount(): void
    {
        $counter = new RateCounter();

        $counter->increment('pack-a', 'token-1');
        $counter->increment('pack-a', 'token-1');

        $GLOBALS['wpas_test_transients'] = array_map(
            static fn ($v) => is_int($v) ? (string) $v : $v,
            $GLOBALS['wpas_test_transients'],
        );

        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->countsFor('pack-a', 'token-1'));
    }

    public function testDifferentTokensUnderTheSamePackHaveIndependentCounters(): void
    {
        $counter = new RateCounter();

        $counter->increment('pack-a', 'token-1');

        $this->assertSame(['minute' => 1, 'hour' => 1], $counter->countsFor('pack-a', 'token-1'));
        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-a', 'token-2'));
    }

    public function testDifferentPacksForTheSameTokenHaveIndependentCounters(): void
    {
        $counter = new RateCounter();

        $counter->increment('pack-a', 'token-1');

        $this->assertSame(['minute' => 1, 'hour' => 1], $counter->countsFor('pack-a', 'token-1'));
        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-b', 'token-1'));
    }

    public function testMinuteBucketResetsOnANewMinuteButHourPersists(): void
    {
        $counter = new RateCounter();
        $counter->increment('pack-a', 'token-1');
        $counter->increment('pack-a', 'token-1');
        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->countsFor('pack-a', 'token-1'));

        // Cross into the next 60s bucket, but stay within the same hour.
        $GLOBALS['wpas_test_time'] += 60;

        $this->assertSame(['minute' => 0, 'hour' => 2], $counter->countsFor('pack-a', 'token-1'));

        $counter->increment('pack-a', 'token-1');
        $this->assertSame(['minute' => 1, 'hour' => 3], $counter->countsFor('pack-a', 'token-1'));
    }

    public function testHourBucketAlsoResetsOnANewHour(): void
    {
        $counter = new RateCounter();
        $counter->increment('pack-a', 'token-1');

        $GLOBALS['wpas_test_time'] += 3600;

        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('pack-a', 'token-1'));
    }
}
