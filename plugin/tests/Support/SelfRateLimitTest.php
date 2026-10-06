<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\RateCounter;
use CounterTableWpdb;
use Specflux\AgentSafety\Plugin\Support\SelfRateLimit;

/** AS-8 (§3.5 item 6): the fixed 10/minute cap check-approval enforces on itself. */
final class SelfRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        \CounterTableWpdb::install();
        $GLOBALS['wpas_test_time'] = 1_800_000_000;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    public function testAdmitsUpToTenCallsPerMinutePerIdentity(): void
    {
        $limit = new SelfRateLimit();

        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($limit->admit('wc:key_7'), "call $i should be admitted");
        }

        $this->assertFalse($limit->admit('wc:key_7'));
    }

    public function testEachIdentityHasItsOwnBucket(): void
    {
        $limit = new SelfRateLimit();

        for ($i = 0; $i < 10; $i++) {
            $limit->admit('wc:key_7');
        }

        $this->assertFalse($limit->admit('wc:key_7'));
        $this->assertTrue($limit->admit('wc:key_8'));
    }

    public function testRetryAfterSecondsIsTheRemainderOfTheCurrentMinuteWindow(): void
    {
        $limit = new SelfRateLimit();

        $this->assertSame(60 - (1_800_000_000 % 60), $limit->retryAfterSeconds());
    }

    public function testTheDeniedCallsReservationIsReleased(): void
    {
        $limit = new SelfRateLimit();
        for ($i = 0; $i < 10; $i++) {
            $limit->admit('wc:key_7');
        }
        $limit->admit('wc:key_7');
        $limit->admit('wc:key_7');

        $this->assertSame(10, (new RateCounter())->countsFor('agent-safety-self', 'wc:key_7')['minute']);
    }

    public function testARequestLandingBetweenReserveAndCheckCannotBothTakeTheLastSlot(): void
    {
        $db = CounterTableWpdb::install();
        $setup = new SelfRateLimit();
        for ($i = 0; $i < 9; $i++) {
            $setup->admit('wc:key_7');
        }

        $requestB = new SelfRateLimit();
        $verdictB = null;
        $db->afterNextInsert = static function () use ($requestB, &$verdictB): void {
            $verdictB = $requestB->admit('wc:key_7');
        };
        $verdictA = (new SelfRateLimit())->admit('wc:key_7');

        $this->assertNotSame($verdictA, $verdictB, 'exactly one of the two takes the 10th slot');
    }
}
