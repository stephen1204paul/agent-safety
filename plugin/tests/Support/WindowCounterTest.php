<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\WindowCounter;

/**
 * The generic fixed-window bucket the tripwires count in, with the same
 * frozen clock {@see RateCounterTest} uses (tests/stubs/wpas-clock.php).
 */
final class WindowCounterTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        \CounterTableWpdb::install();
        $GLOBALS['wpas_test_time'] = self::NOW;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_time'] = \time();
    }

    public function testAFreshSubjectCountsZero(): void
    {
        $this->assertSame(0, (new WindowCounter())->count('a', 60));
    }

    public function testIncrementReturnsTheNewCountAndCountReadsItBack(): void
    {
        $counter = new WindowCounter();

        $this->assertSame(1, $counter->increment('a', 60));
        $this->assertSame(2, $counter->increment('a', 60));
        $this->assertSame(2, $counter->count('a', 60));
    }

    public function testSubjectsAndWindowsAreIndependentBuckets(): void
    {
        $counter = new WindowCounter();
        $counter->increment('a', 60);
        $counter->increment('a', 60);

        $this->assertSame(0, $counter->count('b', 60));
        $this->assertSame(0, $counter->count('a', 3600), 'the same subject in another window is another bucket');
    }

    public function testTheCountResetsWhenTheWindowRollsOver(): void
    {
        $counter = new WindowCounter();
        $counter->increment('a', 60);

        $GLOBALS['wpas_test_time'] = self::NOW + 59;
        $this->assertSame(1, $counter->count('a', 60));

        $GLOBALS['wpas_test_time'] = self::NOW + 60;
        $this->assertSame(0, $counter->count('a', 60));
    }

    public function testEveryKeyCarriesThePluginPrefix(): void
    {
        (new WindowCounter())->increment('a', 60);

        $this->assertNotSame([], $GLOBALS['wpdb']->rows);
        foreach (array_keys($GLOBALS['wpdb']->rows) as $key) {
            $this->assertStringStartsWith('agsafe_win_', $key);
            $this->assertLessThanOrEqual(64, strlen($key));
        }
    }

    public function testDecrementUndoesAnIncrementAndReturnsTheNewCount(): void
    {
        $counter = new WindowCounter();
        $counter->increment('a', 60);
        $counter->increment('a', 60);

        $this->assertSame(1, $counter->decrement('a', 60));
        $this->assertSame(1, $counter->count('a', 60));
    }
}
