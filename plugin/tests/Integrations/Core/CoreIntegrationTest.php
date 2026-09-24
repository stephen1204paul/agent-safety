<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Core;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Integrations\Core\CoreIntegration;
use Specflux\AgentSafety\Plugin\Integrations\Core\CoreVerbCatalog;

/**
 * The Core module is wired unconditionally, so its contract is simpler than
 * Woo's: always available, contributes the core namespace + no elevation
 * rules + one pack, and never touches identity.
 */
final class CoreIntegrationTest extends TestCase
{
    public function testAlwaysAvailable(): void
    {
        $this->assertTrue(CoreIntegration::available());
    }

    public function testRegisterContributesCoreNamespaceAndNoRulesAndOnePack(): void
    {
        $catalog = new VerbCatalog();
        $contributions = CoreIntegration::register($catalog, new IdentityChain(), null);

        // The catalog was mutated in place with the full core verb map.
        $this->assertSame(Tier::Reversible, $catalog->baseTier(CoreVerbCatalog::GET_SITE_INFO));
        $this->assertSame(Tier::Reversible, $catalog->baseTier(CoreVerbCatalog::GET_ENVIRONMENT_INFO));
        $this->assertSame(Tier::Reversible, $catalog->baseTier(CoreVerbCatalog::GET_USER_INFO));

        // Anything outside the three live verbs is fail-closed unclassified.
        $this->assertNull($catalog->baseTier('core/manage-content'));

        $this->assertSame([], $contributions['elevationRules']);
        $this->assertCount(1, $contributions['packs']);

        // D23: the namespace is governed by prefix, never an enumerated list,
        // and no wildcard VERB entries exist to pre-classify unseen verbs.
        $this->assertSame(['core/'], $contributions['governedNamespaces']);
    }

    public function testRegisterAddsNoIdentityProvider(): void
    {
        $identity = new IdentityChain();

        CoreIntegration::register(new VerbCatalog(), $identity, null);

        $this->assertSame([], $identity->providers());
    }
}
