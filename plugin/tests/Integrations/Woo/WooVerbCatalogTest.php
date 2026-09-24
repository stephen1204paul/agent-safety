<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooVerbCatalog;

/**
 * Pins the 16 named abilities as the WHOLE catalogue: no forward-compat
 * entries, no wildcard patterns. Anything else under "woocommerce/" must
 * fail closed as unknown_verb (enforced by the Gate; here we confirm the
 * catalog itself returns no tier for those verbs).
 */
final class WooVerbCatalogTest extends TestCase
{
    public function testCatalogHasExactlySixteenNamedAbilities(): void
    {
        $this->assertCount(16, WooVerbCatalog::MAP);
    }

    public function testNoWildcardEntries(): void
    {
        foreach (array_keys(WooVerbCatalog::MAP) as $verb) {
            $this->assertStringNotContainsString('*', $verb);
        }
    }

    public function testProductDeleteIsSideEffectingNotIrreversible(): void
    {
        $this->assertSame(Tier::SideEffecting, WooVerbCatalog::MAP['woocommerce/product-delete']);
    }

    /** @dataProvider removedForwardCompatVerbs */
    public function testRemovedForwardCompatVerbsAreUnclassified(string $verb): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(WooVerbCatalog::MAP);

        // The fail-closed default: an unmapped woocommerce/ verb has NO base
        // tier, which the Gate turns into a denied unknown_verb decision.
        $this->assertNull($catalog->baseTier($verb));
    }

    /** @return list<list<string>> */
    public static function removedForwardCompatVerbs(): array
    {
        return [
            ['woocommerce/orders-refund'],
            ['woocommerce/customers-email'],
            ['woocommerce/settings-general'],
            ['woocommerce/reports-sales'],
        ];
    }
}
