<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\GateContext;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Tests\Fixtures\BulkDeleteElevationRuleFixture;
use Specflux\AgentSafety\Tests\Fixtures\FulfillmentElevationRuleFixture;
use Specflux\AgentSafety\Tests\Fixtures\WooLikeVerbCatalog;

final class GateTest extends TestCase
{
    private Gate $gate;

    protected function setUp(): void
    {
        // VerbCatalog/TierClassifier are now generic; register the moved Woo
        // verb map + elevation rules explicitly so the woocommerce/* fixtures
        // below keep meaning what they used to (SPEC §2).
        $classifier = new TierClassifier(
            WooLikeVerbCatalog::build(),
            [new FulfillmentElevationRuleFixture(), new BulkDeleteElevationRuleFixture()],
        );
        $this->gate = new Gate($classifier);
    }

    private function ctx(string $verb, array $args, Pack $pack, bool $approval = false, bool $readonly = false): GateContext
    {
        return new GateContext($verb, $args, $pack, $readonly, $approval);
    }

    private function ownerPack(): Pack
    {
        return new Pack(name: 'owner', allow: ['*']);
    }

    private function marketingPack(): Pack
    {
        // Can read + touch catalog, but tier2 is walled off entirely.
        return new Pack(
            name: 'marketing-agency',
            allow: ['woocommerce/products-*', 'woocommerce/reports-*', 'woocommerce/orders-*'],
            denyClass: ['tier2'],
        );
    }

    private function supportPack(): Pack
    {
        // Can operate on orders, but tier2 needs a human.
        return new Pack(
            name: 'support',
            allow: ['woocommerce/orders-*'],
            approvalByClass: ['tier2' => true],
        );
    }

    public function testReadVerbInOwnerPackIsAllowedAsTier0(): void
    {
        $d = $this->gate->evaluate($this->ctx('woocommerce/products-list', [], $this->ownerPack()));
        $this->assertSame(Outcome::Allow, $d->outcome);
        $this->assertSame(Tier::Reversible, $d->tier);
    }

    public function testOwnerCanRefund(): void
    {
        $d = $this->gate->evaluate($this->ctx('woocommerce/orders-refund', ['order' => 1, 'amount' => 5], $this->ownerPack()));
        $this->assertSame(Outcome::Allow, $d->outcome);
        $this->assertSame(Tier::Irreversible, $d->tier);
    }

    public function testDenyClassWallBlocksRefundEvenWhenVerbIsAllowed(): void
    {
        // orders.refund matches "woocommerce/orders-*", but tier2 is hard-denied -> injection-proof.
        $d = $this->gate->evaluate($this->ctx('woocommerce/orders-refund', ['order' => 1], $this->marketingPack()));
        $this->assertSame(Outcome::Deny, $d->outcome);
        $this->assertSame('denied_by_class', $d->reason);
    }

    public function testVerbOutsidePackIsDenied(): void
    {
        $d = $this->gate->evaluate($this->ctx('woocommerce/orders-refund', [], new Pack('readonly', ['woocommerce/products-*'])));
        $this->assertSame(Outcome::Deny, $d->outcome);
        $this->assertSame('not_in_pack', $d->reason);
    }

    public function testTier2RequiresApprovalWhenPackDemandsIt(): void
    {
        $d = $this->gate->evaluate($this->ctx('woocommerce/orders-refund', ['order' => 1], $this->supportPack()));
        $this->assertSame(Outcome::ApprovalRequired, $d->outcome);
    }

    public function testValidApprovalTokenLetsTier2Through(): void
    {
        $d = $this->gate->evaluate($this->ctx('woocommerce/orders-refund', ['order' => 1], $this->supportPack(), approval: true));
        $this->assertSame(Outcome::Allow, $d->outcome);
    }

    public function testOrderStatusUpdateToCompletedElevatesToTier2(): void
    {
        // Non-status update is Tier 1 and allowed under support pack...
        $d1 = $this->gate->evaluate($this->ctx('woocommerce/orders-update', ['note' => 'x'], $this->supportPack()));
        $this->assertSame(Outcome::Allow, $d1->outcome);
        $this->assertSame(Tier::SideEffecting, $d1->tier);

        // ...but flipping status to a fulfillment state elevates to Tier 2 -> approval.
        $d2 = $this->gate->evaluate($this->ctx('woocommerce/orders-update', ['status' => 'completed'], $this->supportPack()));
        $this->assertSame(Outcome::ApprovalRequired, $d2->outcome);
        $this->assertSame(Tier::Irreversible, $d2->tier);
    }

    public function testBulkDeleteElevatesToTier2(): void
    {
        $single = $this->gate->evaluate($this->ctx('woocommerce/products-delete', ['ids' => [1]], $this->ownerPack()));
        $this->assertSame(Tier::SideEffecting, $single->tier);

        $bulk = $this->gate->evaluate($this->ctx('woocommerce/products-delete', ['ids' => [1, 2, 3]], $this->ownerPack()));
        $this->assertSame(Tier::Irreversible, $bulk->tier);
    }

    public function testUnknownVerbFailsClosed(): void
    {
        $d = $this->gate->evaluate($this->ctx('woocommerce/orders-teleport', [], $this->ownerPack()));
        $this->assertSame(Outcome::Deny, $d->outcome);
        $this->assertSame('unknown_verb', $d->reason);
    }

    public function testReadonlyAnnotationOnAWriteVerbFailsClosed(): void
    {
        // Ability lies: claims readonly, but products.update is a write (D3).
        $d = $this->gate->evaluate($this->ctx('woocommerce/products-update', ['id' => 1], $this->ownerPack(), readonly: true));
        $this->assertSame(Outcome::Deny, $d->outcome);
        $this->assertSame('readonly_but_writes', $d->reason);
    }
}
