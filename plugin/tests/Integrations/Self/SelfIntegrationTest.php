<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Self;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Integrations\Self\SelfIntegration;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;

/**
 * AS-8 (§3.5): the registration shape — always-on like Core, contributes the
 * `agent-safety/` namespace, catalogues exactly the one verb, and wires the
 * real Abilities API registration (label/description/category/schema/meta)
 * the way {@see \Specflux\AgentSafety\Plugin\Integrations\Self\CheckApprovalAbilityTest}
 * proves the poll BEHAVIOUR for.
 */
final class SelfIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_added_actions'] = [];
        $GLOBALS['wpas_test_abilities'] = [];
        $GLOBALS['wpas_test_ability_categories'] = [];
    }

    public function testAlwaysAvailable(): void
    {
        $this->assertTrue(SelfIntegration::available());
    }

    public function testRegisterContributesTheNamespaceAndCataloguesTheVerbAtTierZero(): void
    {
        $catalog = new VerbCatalog();
        $contributions = SelfIntegration::register($catalog);

        $this->assertSame(Tier::Reversible, $catalog->baseTier(VerdictPipeline::CHECK_APPROVAL_VERB));
        $this->assertSame(['agent-safety/'], $contributions['governedNamespaces']);
        $this->assertSame([], $contributions['elevationRules']);
        $this->assertSame([], $contributions['packs']);

        // No wildcard entry pre-classifies a future agent-safety/* verb.
        $this->assertNull($catalog->baseTier('agent-safety/some-other-verb'));
    }

    public function testRegisterAbilityWiresBothAbilitiesApiHooks(): void
    {
        SelfIntegration::registerAbility(null, null, null);

        $this->assertArrayHasKey('wp_abilities_api_init', $GLOBALS['wpas_test_added_actions']);
        $this->assertArrayHasKey('wp_abilities_api_categories_init', $GLOBALS['wpas_test_added_actions']);

        // Fire the recorded callbacks the way WordPress would.
        foreach ($GLOBALS['wpas_test_added_actions']['wp_abilities_api_categories_init'] as $callback) {
            $callback();
        }
        foreach ($GLOBALS['wpas_test_added_actions']['wp_abilities_api_init'] as $callback) {
            $callback();
        }

        $this->assertArrayHasKey(SelfIntegration::CATEGORY, $GLOBALS['wpas_test_ability_categories']);
        $this->assertArrayHasKey(VerdictPipeline::CHECK_APPROVAL_VERB, $GLOBALS['wpas_test_abilities']);

        $args = $GLOBALS['wpas_test_abilities'][VerdictPipeline::CHECK_APPROVAL_VERB];

        $this->assertStringStartsWith(
            'Check the status of an approval_required request by its approval_id',
            $args['description']
        );
        $this->assertSame(SelfIntegration::CATEGORY, $args['category']);
        $this->assertTrue($args['meta']['mcp']['public']);
        $this->assertTrue($args['meta']['annotations']['readonly']);
        $this->assertIsString($args['meta']['annotations']['instructions']);
        $this->assertNotSame('', $args['meta']['annotations']['instructions']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['approval_id' => $args['input_schema']['properties']['approval_id']], 'required' => ['approval_id']],
            $args['input_schema']
        );
        $this->assertIsCallable($args['permission_callback']);
        $this->assertIsCallable($args['execute_callback']);
        $this->assertTrue(($args['permission_callback'])());
    }
}
