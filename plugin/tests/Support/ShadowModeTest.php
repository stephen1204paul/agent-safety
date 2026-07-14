<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;

final class ShadowModeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
    }

    public function testNoPackIsShadowedByDefault(): void
    {
        $shadow = new ShadowMode();

        $this->assertSame([], $shadow->packs());
        $this->assertFalse($shadow->isShadow('woo-default-agent'));
    }

    public function testReadsTheConfiguredOption(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent'];
        $shadow = new ShadowMode();

        $this->assertTrue($shadow->isShadow('support-agent'));
        $this->assertFalse($shadow->isShadow('woo-default-agent'));
    }

    public function testAMalformedOptionFailsClosedToNoShadowing(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = 'support-agent'; // not a list

        $this->assertSame([], (new ShadowMode())->packs());
    }

    public function testNonStringAndEmptyEntriesAreDropped(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent', 7, '', null, 'owner'];

        $this->assertSame(['support-agent', 'owner'], (new ShadowMode())->packs());
    }
}
