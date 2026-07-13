<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooIntegration;

/**
 * Exercises {@see WooIntegration::available()} behind a `class_exists('WooCommerce')`
 * shim -- a dummy global `WooCommerce` class is defined lazily, only once needed,
 * so a test asserting unavailability stays meaningful even though PHP can never
 * "undefine" a class once it's declared for the rest of the process.
 */
final class WooIntegrationTest extends TestCase
{
    public function testAvailableIsFalseWithoutAWooCommerceClass(): void
    {
        if (class_exists('WooCommerce', false)) {
            $this->markTestSkipped('A WooCommerce stub was already defined by an earlier test in this process.');
        }

        $this->assertFalse(WooIntegration::available());
    }

    public function testRegisterContributesCatalogIdentityPacksAndNamespaces(): void
    {
        self::defineWooCommerceStub();
        $this->assertTrue(WooIntegration::available());

        $catalog = new VerbCatalog();
        $identity = new IdentityChain();

        $contributions = WooIntegration::register($catalog, $identity, null);

        // VerbCatalog was mutated in place with the Woo verb map.
        $this->assertSame(Tier::Reversible, $catalog->baseTier('woocommerce/products-list'));
        $this->assertSame(Tier::Irreversible, $catalog->baseTier('woocommerce/orders-refund'));
        $this->assertNull($catalog->baseTier('woocommerce/unknown-verb'));

        // No $wpdb passed in -> the WC API key identity provider is NOT added.
        $this->assertSame([], $identity->providers());

        $this->assertNotEmpty($contributions['elevationRules']);
        $this->assertSame(['woocommerce/'], $contributions['governedNamespaces']);

        $packNames = array_map(static fn ($pack) => $pack->name, $contributions['packs']);
        $this->assertContains('support-agent', $packNames);
        $this->assertContains('woo-default-agent', $packNames);
    }

    private static function defineWooCommerceStub(): void
    {
        if (!class_exists('WooCommerce', false)) {
            eval('class WooCommerce {}'); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only class stub, no user input.
        }
    }
}
