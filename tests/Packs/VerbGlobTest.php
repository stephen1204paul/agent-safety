<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Packs\VerbGlob;

/**
 * The one glob dialect shared by {@see Pack::allows()} and
 * {@see \Specflux\AgentSafety\Packs\ArgumentCap::appliesTo()}. Pack's own
 * allow/deny arithmetic is exhaustively covered via {@see
 * \Specflux\AgentSafety\Tests\Packs\PackRegistryTest}; the cases here confirm
 * allows() still delegates correctly now that it routes through this class,
 * plus the new Pack::hasArgumentCaps() accessor.
 */
final class VerbGlobTest extends TestCase
{
    public function testBareAsteriskMatchesAnything(): void
    {
        $this->assertTrue(VerbGlob::matches('*', 'anything/at-all'));
        $this->assertTrue(VerbGlob::matches('*', ''));
    }

    public function testTrailingAsteriskMatchesAnyPrefixedSubject(): void
    {
        $this->assertTrue(VerbGlob::matches('woocommerce/orders-*', 'woocommerce/orders-list'));
        $this->assertTrue(VerbGlob::matches('woocommerce/orders-*', 'woocommerce/orders-'));
        $this->assertFalse(VerbGlob::matches('woocommerce/orders-*', 'woocommerce/products-list'));
    }

    public function testExactLiteralMatchesOnlyItself(): void
    {
        $this->assertTrue(VerbGlob::matches('woocommerce/orders-list', 'woocommerce/orders-list'));
        $this->assertFalse(VerbGlob::matches('woocommerce/orders-list', 'woocommerce/orders-list-extra'));
    }

    public function testNonMatchingPatternReturnsFalse(): void
    {
        $this->assertFalse(VerbGlob::matches('woocommerce/*', 'demo/orders-list'));
    }

    public function testPackAllowsStillDelegatesToVerbGlob(): void
    {
        $pack = new Pack(name: 'p', allow: ['woocommerce/orders-*']);

        $this->assertTrue($pack->allows('woocommerce/orders-list'));
        $this->assertFalse($pack->allows('woocommerce/products-list'));
    }

    public function testNoArgumentCapsMeansHasArgumentCapsIsFalse(): void
    {
        $pack = new Pack(name: 'p', allow: ['*']);

        $this->assertFalse($pack->hasArgumentCaps());
    }

    public function testEmptyArgumentCapsArrayMeansHasArgumentCapsIsFalse(): void
    {
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: []);

        $this->assertFalse($pack->hasArgumentCaps());
    }

    public function testAtLeastOneArgumentCapMeansHasArgumentCapsIsTrue(): void
    {
        $cap = new ArgumentCap('refund_total', 'orders/*', 'amount', maxPerCall: 500.0);
        $pack = new Pack(name: 'p', allow: ['*'], argumentCaps: [$cap]);

        $this->assertTrue($pack->hasArgumentCaps());
    }
}
