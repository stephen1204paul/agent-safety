<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\SelfRateLimit;

/** AS-8 (§3.5 item 6): the fixed 10/minute cap check-approval enforces on itself. */
final class SelfRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_transients'] = [];
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
}
