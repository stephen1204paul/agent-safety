<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Core;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\GateContext;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Integrations\Core\CoreVerbCatalog;

/**
 * Pins the tier assignments AND the fail-closed shape of the map: tests
 * reference the class constants (never string literals) so an upstream
 * rename of a live ability is a one-place change.
 */
final class CoreVerbCatalogTest extends TestCase
{
    /** @var list<string> The three abilities shipped by core today. */
    private const LIVE_VERBS = [
        CoreVerbCatalog::GET_SITE_INFO,
        CoreVerbCatalog::GET_ENVIRONMENT_INFO,
        CoreVerbCatalog::GET_USER_INFO,
    ];

    public function testMapHasExactlyThreeEntries(): void
    {
        $this->assertCount(3, CoreVerbCatalog::MAP);
        $this->assertSame(self::LIVE_VERBS, array_keys(CoreVerbCatalog::MAP));
    }

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

    public function testUnknownCoreVerbIsUnclassified(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(CoreVerbCatalog::MAP);

        // The fail-closed default: an unmapped core verb has NO base tier,
        // which the Gate turns into a denied unknown_verb decision.
        $this->assertNull($catalog->baseTier('core/definitely-not-real'));
    }

    /**
     * One of the six removed PROPOSED merge-proposal verbs: no longer in the
     * map at all, so it classifies AND judges as unknown_verb, exactly like
     * any other unmapped `core/*` ability.
     */
    public function testManageContentClassifiesAsUnknownVerb(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(CoreVerbCatalog::MAP);

        $this->assertNull($catalog->baseTier('core/manage-content'));
    }

    public function testManageContentJudgesAsUnknownVerb(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(CoreVerbCatalog::MAP);

        $gate = new Gate(new TierClassifier($catalog));
        $pack = new Pack(name: 'site-readonly', allow: ['core/manage-content']);

        $decision = $gate->evaluate(new GateContext(verb: 'core/manage-content', args: [], pack: $pack));

        $this->assertFalse($decision->isAllowed());
        $this->assertSame('unknown_verb', $decision->reason);
    }

    public function testNoWildcardEntries(): void
    {
        foreach (array_keys(CoreVerbCatalog::MAP) as $verb) {
            $this->assertStringNotContainsString('*', $verb);
        }
    }
}
