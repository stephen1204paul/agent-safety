<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;

final class PackTest extends TestCase
{
    public function testNoLimitsMeansNoRateLimits(): void
    {
        $pack = new Pack(name: 'p', allow: ['*']);

        $this->assertFalse($pack->hasRateLimits());
    }

    public function testEmptyLimitsArrayMeansNoRateLimits(): void
    {
        $pack = new Pack(name: 'p', allow: ['*'], limits: []);

        $this->assertFalse($pack->hasRateLimits());
    }

    public function testExplicitNullLimitValuesMeanNoRateLimits(): void
    {
        $pack = new Pack(name: 'p', allow: ['*'], limits: ['calls_per_minute' => null, 'calls_per_hour' => null]);

        $this->assertFalse($pack->hasRateLimits());
    }

    public function testCallsPerMinuteAloneCountsAsRateLimited(): void
    {
        $pack = new Pack(name: 'p', allow: ['*'], limits: ['calls_per_minute' => 10]);

        $this->assertTrue($pack->hasRateLimits());
    }

    public function testCallsPerHourAloneCountsAsRateLimited(): void
    {
        $pack = new Pack(name: 'p', allow: ['*'], limits: ['calls_per_hour' => 1000]);

        $this->assertTrue($pack->hasRateLimits());
    }
}
