<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooPacks;
use Specflux\AgentSafety\Policy\Tier;

/**
 * The starter presets are POLICY shipped as code, so these tests pin the
 * safety-critical property of each one — what it can NEVER reach — rather
 * than echoing the declarations back.
 */
final class WooPacksTest extends TestCase
{
    public function testCatalogShipsTheFivePacksWithUniqueNames(): void
    {
        $names = array_map(static fn (Pack $p): string => $p->name, WooPacks::all());

        $this->assertSame(
            ['woo-default-agent', 'support-agent', 'readonly-analyst', 'fulfillment-bot', 'refund-desk'],
            $names,
        );
        $this->assertSame($names, array_unique($names));
    }

    public function testReadonlyAnalystCannotReachAnyWriteEvenIfOneLooksLikeARead(): void
    {
        $pack = $this->pack('readonly-analyst');

        $this->assertTrue($pack->allows('woocommerce/orders-list'));
        $this->assertTrue($pack->allows('woocommerce/reports-sales'));
        $this->assertFalse($pack->allows('woocommerce/products-update'));
        $this->assertFalse($pack->allows('woocommerce/orders-refund'));
        // Belt and braces: even a verb that slipped INTO the allow list could
        // not write — both write classes are hard-denied.
        $this->assertTrue($pack->deniesClass(Tier::SideEffecting));
        $this->assertTrue($pack->deniesClass(Tier::Irreversible));
    }

    public function testFulfillmentBotCannotReachRefundsOrCustomerEmailByConstruction(): void
    {
        $pack = $this->pack('fulfillment-bot');

        $this->assertTrue($pack->allows('woocommerce/orders-update'));
        $this->assertFalse($pack->allows('woocommerce/orders-refund'));
        $this->assertFalse($pack->allows('woocommerce/customers-email'));
        $this->assertFalse($pack->allows('woocommerce/products-update'));
        // Fulfilling is the job: the elevated (Tier-2) status transition needs
        // no approval and no class is denied.
        $this->assertFalse($pack->deniesClass(Tier::Irreversible));
        $this->assertFalse($pack->requiresApproval(Tier::Irreversible));
    }

    public function testRefundDeskApprovalGatesEveryRefundAndBoundsTheSpend(): void
    {
        $pack = $this->pack('refund-desk');

        $this->assertTrue($pack->allows('woocommerce/orders-refund'));
        $this->assertFalse($pack->allows('woocommerce/orders-update'));
        $this->assertTrue($pack->requiresApproval(Tier::Irreversible));
        $this->assertTrue($pack->hasArgumentCaps());

        $cap = $pack->argumentCaps[0];
        $this->assertTrue($cap->appliesTo('woocommerce/orders-refund'));
        $this->assertFalse($cap->appliesTo('woocommerce/orders-list'));
        $this->assertSame('amount', $cap->argPath);
        $this->assertSame(500.0, $cap->maxPerCall);
        $this->assertSame(2000.0, $cap->maxTotalPerDay);
    }

    private function pack(string $name): Pack
    {
        foreach (WooPacks::all() as $pack) {
            if ($pack->name === $name) {
                return $pack;
            }
        }

        self::fail(sprintf('preset "%s" not found', $name));
    }
}
