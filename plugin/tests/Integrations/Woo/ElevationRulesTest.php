<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\Woo\CustomerNoteElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\ForceDeleteElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\OrderFulfillmentElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\ProductPriceOrPublishElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * The Woo rules elevate ONLY on their documented argument shape and never
 * touch anything else — same contract the core rules are held to in
 * {@see \Specflux\AgentSafety\Plugin\Tests\Integrations\Core\ElevationRulesTest}.
 * The force-delete rule is the fail-closed one: only a CLEARLY false flag
 * keeps the base tier, every other shape elevates.
 */
final class ElevationRulesTest extends TestCase
{
    private const ORDER_UPDATE = 'woocommerce/orders-update';
    private const ORDER_UPDATE_STATUS = 'woocommerce/order-update-status';
    private const PRODUCT_DELETE = 'woocommerce/products-delete';
    private const PRODUCT_CREATE = 'woocommerce/product-create';
    private const PRODUCT_UPDATE = 'woocommerce/product-update';
    private const ORDER_ADD_NOTE = 'woocommerce/order-add-note';

    public function testStatusTransitionsWithCustomerFacingSideEffectsElevateToIrreversible(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach ([self::ORDER_UPDATE, self::ORDER_UPDATE_STATUS] as $verb) {
            foreach (['processing', 'completed', 'shipped', 'cancelled', 'refunded', 'Cancelled', ' cancelled', "cancelled\n", " Cancelled \t"] as $status) {
                $this->assertSame(
                    Tier::Irreversible,
                    $rule->apply($verb, ['id' => 42, 'status' => $status], Tier::SideEffecting),
                    sprintf('%s status "%s" must elevate', $verb, $status),
                );
            }
        }
    }

    public function testStatusTransitionsWithoutSideEffectsStayAtBaseTier(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach ([self::ORDER_UPDATE, self::ORDER_UPDATE_STATUS] as $verb) {
            foreach (['pending', 'on-hold'] as $status) {
                $this->assertNull($rule->apply($verb, ['id' => 42, 'status' => $status], Tier::SideEffecting));
            }
            $this->assertNull($rule->apply($verb, ['id' => 42, 'customer_note' => 'x'], Tier::SideEffecting));
        }
    }

    public function testStatusRuleIgnoresOtherVerbs(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        $this->assertNull($rule->apply('woocommerce/orders-create', ['status' => 'cancelled'], Tier::SideEffecting));
        $this->assertNull($rule->apply('core/manage-content', ['status' => 'refunded'], Tier::SideEffecting));
    }

    public function testProductCreateOrUpdateWithAPriceElevatesToIrreversible(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        foreach ([self::PRODUCT_CREATE, self::PRODUCT_UPDATE, 'woocommerce/products-create', 'woocommerce/products-update'] as $verb) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply($verb, ['id' => 1, 'regular_price' => '9.99'], Tier::SideEffecting),
                sprintf('%s with regular_price must elevate', $verb),
            );
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply($verb, ['id' => 1, 'sale_price' => '7.99'], Tier::SideEffecting),
                sprintf('%s with sale_price must elevate', $verb),
            );
        }
    }

    public function testProductCreateOrUpdateThatPublishesElevatesToIrreversible(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        foreach (['publish', 'future', 'Publish', ' publish', "publish\n", " Publish \t"] as $status) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::PRODUCT_CREATE, ['status' => $status], Tier::SideEffecting),
                sprintf('status "%s" must elevate', $status),
            );
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::PRODUCT_UPDATE, ['status' => $status], Tier::SideEffecting),
                sprintf('status "%s" must elevate', $status),
            );
        }
    }

    public function testProductCreateOrUpdateWithoutPriceOrPublishStaysAtBaseTier(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        $this->assertNull($rule->apply(self::PRODUCT_CREATE, ['name' => 'Widget'], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['status' => 'draft'], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['regular_price' => null], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['sale_price' => null], Tier::SideEffecting));
    }

    public function testProductPriceOrPublishRuleIgnoresOtherVerbs(): void
    {
        $rule = new ProductPriceOrPublishElevationRule();

        $this->assertNull($rule->apply(self::PRODUCT_DELETE, ['regular_price' => '9.99'], Tier::Irreversible));
        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['status' => 'publish'], Tier::SideEffecting));
    }

    public function testCustomerNoteTrueElevatesToIrreversible(): void
    {
        $rule = new CustomerNoteElevationRule();

        foreach ([true, 1, '1'] as $customerNote) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::ORDER_ADD_NOTE, ['id' => 42, 'note' => 'hi', 'customer_note' => $customerNote], Tier::SideEffecting),
                sprintf('customer_note=%s must elevate', var_export($customerNote, true)),
            );
        }
    }

    public function testCustomerNoteFalseOrAbsentStaysAtBaseTier(): void
    {
        $rule = new CustomerNoteElevationRule();

        $this->assertNull($rule->apply(self::ORDER_ADD_NOTE, ['id' => 42, 'note' => 'hi'], Tier::SideEffecting));
        foreach ([false, 0, '', null] as $customerNote) {
            $this->assertNull(
                $rule->apply(self::ORDER_ADD_NOTE, ['id' => 42, 'note' => 'hi', 'customer_note' => $customerNote], Tier::SideEffecting),
                sprintf('customer_note=%s must not elevate', var_export($customerNote, true)),
            );
        }
    }

    public function testCustomerNoteRuleIgnoresOtherVerbs(): void
    {
        $rule = new CustomerNoteElevationRule();

        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['customer_note' => true], Tier::SideEffecting));
        $this->assertNull($rule->apply(self::PRODUCT_UPDATE, ['customer_note' => true], Tier::SideEffecting));
    }

    public function testForceFlagThatIsNotClearlyFalseElevatesProductDeleteToIrreversible(): void
    {
        $rule = new ForceDeleteElevationRule();

        foreach ([true, 1, '1', 'true', 'TRUE', 'yes', 'no', 2, 0.0, [1], ['false'], null] as $force) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::PRODUCT_DELETE, ['id' => 7, 'force' => $force], Tier::SideEffecting),
                sprintf('force=%s must elevate', var_export($force, true)),
            );
        }
    }

    public function testClearlyFalseForceFlagKeepsTheBaseTier(): void
    {
        $rule = new ForceDeleteElevationRule();

        $this->assertNull($rule->apply(self::PRODUCT_DELETE, ['id' => 7], Tier::SideEffecting), 'absent');
        foreach ([false, 0, '0', '', 'false', 'FALSE', 'False'] as $force) {
            $this->assertNull(
                $rule->apply(self::PRODUCT_DELETE, ['id' => 7, 'force' => $force], Tier::SideEffecting),
                sprintf('force=%s must not elevate', var_export($force, true)),
            );
        }
    }

    public function testForceRuleIgnoresOtherVerbs(): void
    {
        $rule = new ForceDeleteElevationRule();

        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['id' => 42, 'force' => true], Tier::SideEffecting));
        $this->assertNull($rule->apply('woocommerce/products-update', ['id' => 7, 'force' => true], Tier::SideEffecting));
    }
}
