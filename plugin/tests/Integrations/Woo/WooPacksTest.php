<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\ArgumentCap;
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
    public function testCatalogShipsTheFourPacksWithUniqueNames(): void
    {
        $names = array_map(static fn (Pack $p): string => $p->name, WooPacks::all());

        $this->assertSame(
            ['woo-default-agent', 'support-agent', 'readonly-analyst', 'fulfillment-bot'],
            $names,
        );
        $this->assertSame($names, array_unique($names));
    }

    public function testReadonlyAnalystCannotReachAnyWriteEvenIfOneLooksLikeARead(): void
    {
        $pack = $this->pack('readonly-analyst');

        $this->assertTrue($pack->allows('woocommerce/orders-list'));
        $this->assertTrue($pack->allows('woocommerce/products-query'));
        $this->assertTrue($pack->allows('woocommerce/orders-query'));
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

    public function testFulfillmentBotPinsTheStatusAndForbidsMoneyAndIdentityFields(): void
    {
        $pack = $this->pack('fulfillment-bot');

        $status = $pack->argumentCaps[0];
        $this->assertSame('order_status', $status->id);
        $this->assertTrue($status->appliesTo('woocommerce/orders-update'));
        $this->assertSame('status', $status->argPath);
        $this->assertSame(['pending', 'on-hold', 'processing', 'completed', 'shipped'], $status->allowedValues);
        // Nothing in this pack asks a human, so the statuses that move money
        // or email the customer on their way out are not for it to set.
        $this->assertNotContains('cancelled', $status->allowedValues);
        $this->assertNotContains('refunded', $status->allowedValues);

        $forbidden = array_values(array_filter($pack->argumentCaps, static fn (ArgumentCap $cap): bool => $cap->forbidden));
        $this->assertSame(
            ['set_paid', 'customer_id', 'billing', 'shipping', 'line_items', 'shipping_lines', 'fee_lines', 'coupon_lines', 'transaction_id'],
            array_map(static fn (ArgumentCap $cap): string => $cap->argPath, $forbidden),
        );
        foreach ($forbidden as $cap) {
            $this->assertSame($cap->argPath, $cap->id, 'a forbidden-key denial must name the key');
            $this->assertTrue($cap->appliesTo('woocommerce/orders-update'));
            $this->assertFalse($cap->appliesTo('woocommerce/orders-get'));
        }
    }

    public function testWooDefaultAgentResolvesTheSingularSessionVisibleVerbs(): void
    {
        $pack = $this->pack('woo-default-agent');

        // Widened to the session-visible singular names; tier/elevation
        // still governs whether a Tier-2 call needs approval, but the
        // allow list itself no longer blocks these verbs.
        $this->assertTrue($pack->allows('woocommerce/product-update'));
        $this->assertTrue($pack->allows('woocommerce/product-create'));
        $this->assertTrue($pack->allows('woocommerce/order-add-note'));
        $this->assertTrue($pack->allows('woocommerce/order-update-status'));
    }

    public function testSupportAgentAlsoGetsTheSingularSessionVisibleVerbs(): void
    {
        $pack = $this->pack('support-agent');

        $this->assertTrue($pack->allows('woocommerce/product-update'));
        $this->assertTrue($pack->allows('woocommerce/order-add-note'));
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
