<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Core;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Integrations\Core\CoreVerbCatalog;

/**
 * Pins the tier assignments AND the fail-closed shape of the map: tests
 * reference the class constants (never string literals) so an upstream
 * rename of a proposed ability is a one-place change.
 */
final class CoreVerbCatalogTest extends TestCase
{
    /** @var list<string> The three abilities shipped by core today. */
    private const LIVE_VERBS = [
        CoreVerbCatalog::GET_SITE_INFO,
        CoreVerbCatalog::GET_ENVIRONMENT_INFO,
        CoreVerbCatalog::GET_USER_INFO,
    ];

    public function testLiveCoreAbilitiesClassifyReversible(): void
    {
        foreach (self::LIVE_VERBS as $verb) {
            $this->assertSame(
                Tier::Reversible,
                CoreVerbCatalog::MAP[$verb],
                sprintf('%s must classify reversible', $verb),
            );
        }
    }

    public function testManageSettingsAndUsersAreIrreversible(): void
    {
        $this->assertSame(Tier::Irreversible, CoreVerbCatalog::MAP[CoreVerbCatalog::MANAGE_SETTINGS]);
        $this->assertSame(Tier::Irreversible, CoreVerbCatalog::MAP[CoreVerbCatalog::MANAGE_USERS]);
    }

    public function testUnknownCoreVerbIsUnclassified(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(CoreVerbCatalog::MAP);

        // The fail-closed default: an unmapped core verb has NO base tier,
        // which the Gate turns into a denied unknown_verb decision.
        $this->assertNull($catalog->baseTier('core/definitely-not-real'));
    }

    public function testNoWildcardEntries(): void
    {
        foreach (array_keys(CoreVerbCatalog::MAP) as $verb) {
            $this->assertStringNotContainsString('*', $verb);
        }
    }
}
