<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\LimitPolicy;

final class LimitPolicyTest extends TestCase
{
    private LimitPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new LimitPolicy();
    }

    public function testNoLimitsAlwaysAllows(): void
    {
        $check = $this->policy->evaluate([], ['minute' => 1000, 'hour' => 100000]);

        $this->assertTrue($check->allowed);
        $this->assertNull($check->trippedLimit);
    }

    public function testExplicitNullLimitsAreTreatedAsUnlimited(): void
    {
        $check = $this->policy->evaluate(
            ['calls_per_minute' => null, 'calls_per_hour' => null],
            ['minute' => 1000, 'hour' => 100000],
        );

        $this->assertTrue($check->allowed);
    }

    public function testUnderThePerMinuteCapIsAllowed(): void
    {
        $check = $this->policy->evaluate(['calls_per_minute' => 5], ['minute' => 4, 'hour' => 0]);

        $this->assertTrue($check->allowed);
    }

    public function testAtThePerMinuteCapIsDenied(): void
    {
        // 5 already recorded + this call would be the 6th -> denies at >= cap.
        $check = $this->policy->evaluate(['calls_per_minute' => 5], ['minute' => 5, 'hour' => 0]);

        $this->assertFalse($check->allowed);
        $this->assertSame('calls_per_minute', $check->trippedLimit);
    }

    public function testOverThePerMinuteCapIsDenied(): void
    {
        $check = $this->policy->evaluate(['calls_per_minute' => 5], ['minute' => 9, 'hour' => 0]);

        $this->assertFalse($check->allowed);
        $this->assertSame('calls_per_minute', $check->trippedLimit);
    }

    public function testUnderThePerHourCapIsAllowed(): void
    {
        $check = $this->policy->evaluate(['calls_per_hour' => 100], ['minute' => 0, 'hour' => 99]);

        $this->assertTrue($check->allowed);
    }

    public function testAtThePerHourCapIsDenied(): void
    {
        $check = $this->policy->evaluate(['calls_per_hour' => 100], ['minute' => 0, 'hour' => 100]);

        $this->assertFalse($check->allowed);
        $this->assertSame('calls_per_hour', $check->trippedLimit);
    }

    public function testBothConfiguredAndOnlyHourTripsReportsHour(): void
    {
        $check = $this->policy->evaluate(
            ['calls_per_minute' => 10, 'calls_per_hour' => 100],
            ['minute' => 3, 'hour' => 100],
        );

        $this->assertFalse($check->allowed);
        $this->assertSame('calls_per_hour', $check->trippedLimit);
    }

    public function testBothConfiguredAndMinuteTripsFirstEvenIfHourAlsoTrips(): void
    {
        // Minute is the tighter, sooner-to-clear window -> reported first.
        $check = $this->policy->evaluate(
            ['calls_per_minute' => 10, 'calls_per_hour' => 100],
            ['minute' => 10, 'hour' => 100],
        );

        $this->assertFalse($check->allowed);
        $this->assertSame('calls_per_minute', $check->trippedLimit);
    }
}
