<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Core;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Core\BulkContentDeleteElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Core\CoreVerbCatalog;
use Specflux\AgentSafety\Plugin\Integrations\Core\PublishElevationRule;
use Specflux\AgentSafety\Plugin\Integrations\Core\UserRoleChangeElevationRule;
use Specflux\AgentSafety\Policy\Tier;

/**
 * One positive + one negative per rule (spec §4): a rule must elevate ONLY
 * on its documented argument shape, and never touch anything else.
 */
final class ElevationRulesTest extends TestCase
{
    public function testPublishStatusElevatesToIrreversible(): void
    {
        $rule = new PublishElevationRule();

        $this->assertSame(
            Tier::Irreversible,
            $rule->apply(CoreVerbCatalog::MANAGE_CONTENT, ['status' => 'publish'], Tier::SideEffecting),
        );
        $this->assertSame(
            Tier::Irreversible,
            $rule->apply(CoreVerbCatalog::MANAGE_CONTENT, ['status' => 'future'], Tier::SideEffecting),
        );
    }

    public function testDraftStatusStaysAtBaseTier(): void
    {
        $rule = new PublishElevationRule();

        $this->assertNull(
            $rule->apply(CoreVerbCatalog::MANAGE_CONTENT, ['status' => 'draft'], Tier::SideEffecting),
        );
    }

    public function testBulkDeleteOverThresholdElevatesToIrreversible(): void
    {
        $rule = $this->bulkRule();

        // Six ids: one over the threshold.
        $this->assertSame(
            Tier::Irreversible,
            $rule->apply(CoreVerbCatalog::MANAGE_CONTENT, [
                'action' => 'delete',
                'ids' => range(1, 6),
            ], Tier::SideEffecting),
        );
    }

    public function testDeleteAtExactThresholdDoesNotElevate(): void
    {
        $rule = $this->bulkRule();

        // Five ids IS the threshold; only MORE than five is bulk.
        $this->assertNull(
            $rule->apply(CoreVerbCatalog::MANAGE_CONTENT, [
                'action' => 'delete',
                'ids' => range(1, 5),
            ], Tier::SideEffecting),
        );
    }

    public function testExplicitBulkFlagElevatesEvenForOneId(): void
    {
        $rule = $this->bulkRule();

        $this->assertSame(
            Tier::Irreversible,
            $rule->apply(CoreVerbCatalog::MANAGE_CONTENT, [
                'action' => 'trash',
                'bulk' => true,
                'ids' => [1],
            ], Tier::SideEffecting),
        );
    }

    public function testRoleKeyElevatesToIrreversible(): void
    {
        $rule = new UserRoleChangeElevationRule();

        $this->assertSame(
            Tier::Irreversible,
            $rule->apply(CoreVerbCatalog::MANAGE_USERS, ['role' => 'administrator'], Tier::Irreversible),
        );
    }

    public function testNonPrivilegeUserEditDoesNotElevate(): void
    {
        $rule = new UserRoleChangeElevationRule();

        $this->assertNull(
            $rule->apply(CoreVerbCatalog::MANAGE_USERS, ['display_name' => 'x'], Tier::Irreversible),
        );
    }

    public function testRulesIgnoreOtherVerbsEntirely(): void
    {
        // A Woo verb shares no shape with any core rule.
        $foreign = 'woocommerce/orders-update';

        $this->assertNull((new PublishElevationRule())->apply($foreign, ['status' => 'publish'], Tier::SideEffecting));
        $this->assertNull($this->bulkRule()->apply($foreign, ['action' => 'delete', 'ids' => range(1, 9)], Tier::SideEffecting));
        $this->assertNull((new UserRoleChangeElevationRule())->apply($foreign, ['role' => 'administrator'], Tier::SideEffecting));
    }

    private function bulkRule(): ElevationRule
    {
        return new BulkContentDeleteElevationRule();
    }
}
