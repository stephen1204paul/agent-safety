<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\RateCounter;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;

/**
 * Distinct simulated calls pass distinct args on purpose: admit() memoizes the
 * verdict per (pack, token, verb, args) within a request, because WordPress
 * re-checks permissions for the SAME call (WP_Ability::execute()'s
 * unconditional re-check, plus both gate seams when active). Identical args
 * would be treated as the host re-checking one call, not a new call.
 */
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
            $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['call' => $i]));
        }

        // Short-circuit means the counter is never touched for an unlimited pack.
        $this->assertSame(['minute' => 0, 'hour' => 0], $counter->countsFor('unlimited', 'token-1'));
    }

    public function testCallsUnderTheCapAreAdmittedAndCounted(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 2]);
        $counter = new RateCounter();
        $gate = new RateLimitGate($counter);

        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['call' => 1]));
        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['call' => 2]));

        $this->assertSame(['minute' => 2, 'hour' => 2], $counter->countsFor('capped', 'token-1'));
    }

    public function testCallBeyondTheCapIsDeniedAndNamesTheLimit(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = new RateLimitGate();

        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['call' => 1]));
        $this->assertSame('calls_per_minute', $gate->admit($pack, 'token-1', 'ns/verb', ['call' => 2]));
    }

    public function testADeniedCallDoesNotConsumeQuota(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $counter = new RateCounter();
        $gate = new RateLimitGate($counter);

        $gate->admit($pack, 'token-1', 'ns/verb', ['call' => 1]); // consumes the only slot
        $gate->admit($pack, 'token-1', 'ns/verb', ['call' => 2]); // denied
        $gate->admit($pack, 'token-1', 'ns/verb', ['call' => 3]); // denied again

        // Still exactly 1 — the two denials never incremented the counter.
        $this->assertSame(1, $counter->countsFor('capped', 'token-1')['minute']);
    }

    public function testDifferentIdentitiesUnderTheSamePackAreCappedIndependently(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = new RateLimitGate();

        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['call' => 1]));
        $this->assertNull($gate->admit($pack, 'token-2', 'ns/verb', ['call' => 1]));
    }

    public function testNullTokenSharesOneAnonymousBucket(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = new RateLimitGate();

        $this->assertNull($gate->admit($pack, null, 'ns/verb', ['call' => 1]));
        $this->assertSame('calls_per_minute', $gate->admit($pack, null, 'ns/verb', ['call' => 2]));
    }

    /**
     * REGRESSION (live smoke test, 2026-07-07): the permission callback runs
     * twice per executed call on a real install (mcp-adapter's check_permission
     * plus WP_Ability::execute()'s unconditional re-check). One call must
     * consume exactly ONE quota unit however many times the host re-checks it.
     */
    public function testSameCallRecheckedByTheHostCountsOnce(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 2]);
        $counter = new RateCounter();
        $gate = new RateLimitGate($counter);

        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['id' => 7]));
        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['id' => 7])); // host re-check

        $this->assertSame(1, $counter->countsFor('capped', 'token-1')['minute']);

        // The memo did not eat a slot: a genuinely new call still fits the cap.
        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['id' => 8]));
        $this->assertSame('calls_per_minute', $gate->admit($pack, 'token-1', 'ns/verb', ['id' => 9]));
    }

    /**
     * With calls_per_minute=1 the pre-memo behavior denied the very call it had
     * just admitted (the in-request re-check saw a full window). The re-check
     * must return the call's own admitted verdict.
     */
    public function testCapOfOneAdmitsItsOwnRecheck(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $gate = new RateLimitGate();

        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['id' => 1]));
        $this->assertNull($gate->admit($pack, 'token-1', 'ns/verb', ['id' => 1])); // re-check of same call
        $this->assertSame('calls_per_minute', $gate->admit($pack, 'token-1', 'ns/verb', ['id' => 2]));
    }

    /** A denied verdict is equally stable across the host's re-checks. */
    public function testDeniedVerdictIsStableAcrossRechecks(): void
    {
        $pack = new Pack(name: 'capped', allow: ['*'], limits: ['calls_per_minute' => 1]);
        $counter = new RateCounter();
        $gate = new RateLimitGate($counter);

        $gate->admit($pack, 'token-1', 'ns/verb', ['id' => 1]);
        $this->assertSame('calls_per_minute', $gate->admit($pack, 'token-1', 'ns/verb', ['id' => 2]));
        $this->assertSame('calls_per_minute', $gate->admit($pack, 'token-1', 'ns/verb', ['id' => 2]));
        $this->assertSame(1, $counter->countsFor('capped', 'token-1')['minute']);
    }
}
