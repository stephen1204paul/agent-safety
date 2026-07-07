<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\RateCounter;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;

final class RateLimitGateTest extends TestCase
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

    public function testUnlimitedPackAlwaysAdmitsWithoutTouchingTheCounter(): void
    {
        $pack = new Pack(name: 'unlimited', allow: ['*']);
        $counter = new RateCounter();
        $gate = new RateLimitGate($counter);

        for ($i = 0; $i < 5; $i++) {
            $this->assertNull($gate->admit($pack, 'token-1'));
        }

        // Short-circuit means the counter is never touched for an unlimited pack.
        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('unlimited', 'token-1'));
    }

    public function testCallsUnderTheCapAreAdmittedAndCounted(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 2]);
        $counter = new RateCounter();
        $gate = new RateLimitGate($counter);

        $this->assertNull($gate->admit($pack, 'token-1'));
        $this->assertNull($gate->admit($pack, 'token-1'));

        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->countsFor('capped', 'token-1'));
    }

    public function testCallBeyondTheCapIsDeniedAndNamesTheLimit(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = new RateLimitGate();

        $this->assertNull($gate->admit($pack, 'token-1'));
        $this->assertSame('calls_per_minute', $gate->admit($pack, 'token-1'));
    }

    public function testADeniedCallDoesNotConsumeQuota(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $counter = new RateCounter();
        $gate = new RateLimitGate($counter);

        $gate->admit($pack, 'token-1');       // consumes the only slot
        $gate->admit($pack, 'token-1');       // denied
        $gate->admit($pack, 'token-1');       // denied again

        // Still exactly 1 — the two denials never incremented the counter.
        $this->assertSame(1, $counter->countsFor('capped', 'token-1')['minute']);
    }

    public function testDifferentIdentitiesUnderTheSamePackAreCappedIndependently(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = new RateLimitGate();

        $this->assertNull($gate->admit($pack, 'token-1'));
        $this->assertNull($gate->admit($pack, 'token-2'));
    }

    public function testNullTokenSharesOneAnonymousBucket(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = new RateLimitGate();

        $this->assertNull($gate->admit($pack, null));
        $this->assertSame('calls_per_minute', $gate->admit($pack, null));
    }
}
