<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\WooAgentSafety\Packs\PackRegistry;
use Specflux\WooAgentSafety\Policy\Tier;

final class PackRegistryTest extends TestCase
{
    public function testUnboundSubjectGetsDefaultPack(): void
    {
        $reg = PackRegistry::withBuiltins();

        $this->assertSame('default-agent', $reg->resolve(null)->name);
        $this->assertSame('default-agent', $reg->resolve('key_99')->name);
    }

    public function testBindingResolvesToNamedPack(): void
    {
        $reg = PackRegistry::withBuiltins([
            'key_2' => 'support-agent',
            'key_7' => 'owner',
        ]);

        $this->assertSame('support-agent', $reg->resolve('key_2')->name);
        $this->assertSame('owner', $reg->resolve('key_7')->name);
        // Unlisted key still falls back to the default.
        $this->assertSame('default-agent', $reg->resolve('key_3')->name);
    }

    public function testDanglingBindingFallsBackToDefaultNeverWidens(): void
    {
        $reg = PackRegistry::withBuiltins(['key_2' => 'does-not-exist']);

        $this->assertSame('default-agent', $reg->resolve('key_2')->name);
    }

    public function testTwoSubjectsGetDifferentAllowSetsAndTierVerdicts(): void
    {
        $reg = PackRegistry::withBuiltins([
            'key_owner' => 'owner',
            'key_support' => 'support-agent',
        ]);

        $owner = $reg->resolve('key_owner');
        $support = $reg->resolve('key_support');

        // Different allow-sets: owner admits anything; support only catalog/orders.
        $this->assertTrue($owner->allows('woocommerce/coupons-create'));
        $this->assertFalse($support->allows('woocommerce/coupons-create'));

        // The injection-proof wall: support hard-denies Tier-2; owner does not.
        $this->assertTrue($support->deniesClass(Tier::Irreversible));
        $this->assertFalse($owner->deniesClass(Tier::Irreversible));

        // The default sits between them: Tier-2 reachable but approval-gated.
        $default = $reg->resolve(null);
        $this->assertFalse($default->deniesClass(Tier::Irreversible));
        $this->assertTrue($default->requiresApproval(Tier::Irreversible));
    }

    public function testBuiltinsAndNamesExposeCatalog(): void
    {
        $reg = PackRegistry::withBuiltins();

        $this->assertSame(['owner', 'default-agent', 'support-agent'], $reg->names());
        $this->assertNull($reg->get('nope'));
        $this->assertSame('owner', $reg->get('owner')?->name);
    }
}
