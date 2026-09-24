<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooIntegration;

/**
 * Runs the REAL TierClassifier — built from WooIntegration's own catalog
 * contribution and elevation rules, exactly as the plugin bootstrap builds
 * it — over every case in fixtures/commerce-tier-parity.json (S19). Session
 * (this fixture) and MCP-bridge naming both feed the same catalog + rules, so
 * this is the one place drift between the two surfaces would show up.
 */
final class CommerceTierParityTest extends TestCase
{
    public function testEveryFixtureCaseClassifiesToItsExpectedTier(): void
    {
        // Deliberately no WooCommerce class stub: WooIntegration::register()
        // never calls available() itself, and defining a global WooCommerce
        // class here would leak into WooIntegrationTest's own
        // "unavailable without WooCommerce" assertion (test order dependent).
        $catalog = new VerbCatalog();
        $contributions = WooIntegration::register($catalog, new IdentityChain(), null);
        $classifier = new TierClassifier($catalog, $contributions['elevationRules']);

        $fixturePath = __DIR__ . '/../../Fixtures/commerce-tier-parity.json';
        $raw = file_get_contents($fixturePath);
        $this->assertIsString($raw, 'fixture must be readable');

        /** @var list<array{ability: string, args: array<string, mixed>, tier: int}> $cases */
        $cases = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($cases, 'fixture must not be empty');

        foreach ($cases as $case) {
            $expected = Tier::from($case['tier']);
            $actual = $classifier->classify($case['ability'], $case['args']);

            $this->assertSame(
                $expected,
                $actual,
                sprintf(
                    '%s with args %s expected tier %d, got %s',
                    $case['ability'],
                    json_encode($case['args']),
                    $case['tier'],
                    $actual === null ? 'null (unknown verb)' : (string) $actual->value,
                ),
            );
        }
    }
}
