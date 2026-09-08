<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\Woo\ForceDeleteElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Woo\OrderFulfillmentElevationRule;
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
    private const PRODUCT_DELETE = 'woocommerce/products-delete';

    public function testStatusTransitionsWithCustomerFacingSideEffectsElevateToIrreversible(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach (['processing', 'completed', 'shipped', 'cancelled', 'refunded', 'Cancelled'] as $status) {
            $this->assertSame(
                Tier::Irreversible,
                $rule->apply(self::ORDER_UPDATE, ['id' => 42, 'status' => $status], Tier::SideEffecting),
                sprintf('status "%s" must elevate', $status),
            );
        }
    }

    public function testStatusTransitionsWithoutSideEffectsStayAtBaseTier(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        foreach (['pending', 'on-hold'] as $status) {
            $this->assertNull($rule->apply(self::ORDER_UPDATE, ['id' => 42, 'status' => $status], Tier::SideEffecting));
        }
        $this->assertNull($rule->apply(self::ORDER_UPDATE, ['id' => 42, 'customer_note' => 'x'], Tier::SideEffecting));
    }

    public function testStatusRuleIgnoresOtherVerbs(): void
    {
        $rule = new OrderFulfillmentElevationRule();

        $this->assertNull($rule->apply('woocommerce/orders-create', ['status' => 'cancelled'], Tier::SideEffecting));
        $this->assertNull($rule->apply('core/manage-content', ['status' => 'refunded'], Tier::SideEffecting));
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
