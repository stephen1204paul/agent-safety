<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Tests\Fixtures\BulkDeleteElevationRuleFixture;
use Specflux\AgentSafety\Tests\Fixtures\FulfillmentElevationRuleFixture;
use Specflux\AgentSafety\Tests\Fixtures\WooLikeVerbCatalog;

final class TierClassifierTest extends TestCase
{
    public function testDefaultConstructionIsAnEmptyCatalogAndFailsClosed(): void
    {
        $classifier = new TierClassifier();

        $this->assertNull($classifier->classify('anything/at-all'));
    }

    public function testKnownVerbReturnsBaseTierWithNoRules(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible]);
        $classifier = new TierClassifier($catalog);

        $this->assertSame(Tier::Reversible, $classifier->classify('demo/read'));
    }

    public function testElevationRuleThatDoesNotFireLeavesBaseTierUntouched(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new FulfillmentElevationRuleFixture()]);

        $this->assertSame(Tier::SideEffecting, $classifier->classify('woocommerce/orders-update', ['note' => 'x']));
    }

    public function testFulfillmentStatusElevatesOrderUpdateToIrreversible(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new FulfillmentElevationRuleFixture()]);

        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/orders-update', ['status' => 'completed']));
    }

    public function testBulkDeleteElevatesProductsDeleteToIrreversible(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new BulkDeleteElevationRuleFixture()]);

        $this->assertSame(Tier::SideEffecting, $classifier->classify('woocommerce/products-delete', ['ids' => [1]]));
        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/products-delete', ['ids' => [1, 2, 3]]));
    }

    public function testMultipleRulesStackInOrder(): void
    {
        $classifier = new TierClassifier(
            WooLikeVerbCatalog::build(),
            [new FulfillmentElevationRuleFixture(), new BulkDeleteElevationRuleFixture()],
        );

        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/orders-update', ['status' => 'shipped']));
        $this->assertSame(Tier::Irreversible, $classifier->classify('woocommerce/products-delete', ['ids' => [1, 2]]));
    }

    public function testUnknownVerbIsNullRegardlessOfRules(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build(), [new FulfillmentElevationRuleFixture()]);

        $this->assertNull($classifier->classify('woocommerce/orders-teleport', ['status' => 'completed']));
    }

    public function testIsReadonlyButWritesStillFailsClosedOnAWriteVerb(): void
    {
        $classifier = new TierClassifier(WooLikeVerbCatalog::build());

        $this->assertTrue($classifier->isReadonlyButWrites('woocommerce/products-update', true, ['id' => 1]));
        $this->assertFalse($classifier->isReadonlyButWrites('woocommerce/products-list', true, []));
        $this->assertFalse($classifier->isReadonlyButWrites('woocommerce/products-update', false, ['id' => 1]));
    }
}
