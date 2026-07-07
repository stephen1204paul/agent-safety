<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Packs;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Packs\PackRegistry;
use Specflux\AgentSafety\Policy\Tier;

final class PackRegistryTest extends TestCase
{
    public function testUnboundSubjectGetsDefaultPack(): void
    {
        $reg = PackRegistry::withBuiltins();

        $this->assertSame('default-agent', $reg->resolve(null)->name);
        $this->assertSame('default-agent', $reg->resolve('wc:key_99')->name);
    }

    public function testDefaultAgentBuiltinIsGenericFailClosed(): void
    {
        $reg = PackRegistry::withBuiltins();

        $default = $reg->resolve(null);
        $this->assertFalse($default->allows('anything/at-all'));
        $this->assertFalse($default->allows('*'));
    }

    public function testBindingResolvesToARegisteredPack(): void
    {
        $reg = PackRegistry::withBuiltins([
            'wc:key_2' => 'custom-agent',
            'wc:key_7' => 'owner',
        ]);
        $reg->register(new Pack(name: 'custom-agent', allow: ['demo/read-*']));

        $this->assertSame('custom-agent', $reg->resolve('wc:key_2')->name);
        $this->assertSame('owner', $reg->resolve('wc:key_7')->name);
        // Unlisted key still falls back to the default.
        $this->assertSame('default-agent', $reg->resolve('wc:key_3')->name);
    }

    public function testDanglingBindingFallsBackToDefaultNeverWidens(): void
    {
        $reg = PackRegistry::withBuiltins(['wc:key_2' => 'does-not-exist']);

        $this->assertSame('default-agent', $reg->resolve('wc:key_2')->name);
    }

    public function testRegisterAddsAndOverwritesPacks(): void
    {
        $reg = PackRegistry::withBuiltins();
        $reg->register(new Pack(name: 'extra', allow: ['demo/a-*']));

        $this->assertSame(['owner', 'default-agent', 'extra'], $reg->names());
        $this->assertTrue($reg->get('extra')?->allows('demo/a-read'));
        $this->assertFalse($reg->get('extra')?->allows('demo/b-read'));

        // Same name registered again overwrites, doesn't duplicate.
        $reg->register(new Pack(name: 'extra', allow: ['*']));
        $this->assertSame(['owner', 'default-agent', 'extra'], $reg->names());
        $this->assertTrue($reg->get('extra')?->allows('demo/b-read'));
    }

    public function testTwoSubjectsGetDifferentAllowSetsAndTierVerdicts(): void
    {
        $reg = PackRegistry::withBuiltins([
            'wc:key_owner' => 'owner',
            'wc:key_walled' => 'walled-agent',
        ]);
        $reg->register(new Pack(
            name: 'walled-agent',
            allow: ['demo/catalog-*'],
            denyClass: ['tier2'],
        ));

        $owner = $reg->resolve('wc:key_owner');
        $walled = $reg->resolve('wc:key_walled');

        // Different allow-sets: owner admits anything; the walled pack only its catalog.
        $this->assertTrue($owner->allows('demo/anything-create'));
        $this->assertFalse($walled->allows('demo/anything-create'));

        // The injection-proof wall: the walled pack hard-denies Tier-2; owner does not.
        $this->assertTrue($walled->deniesClass(Tier::Irreversible));
        $this->assertFalse($owner->deniesClass(Tier::Irreversible));
    }

    public function testBuiltinsAndNamesExposeCatalog(): void
    {
        $reg = PackRegistry::withBuiltins();

        $this->assertSame(['owner', 'default-agent'], $reg->names());
        $this->assertNull($reg->get('nope'));
        $this->assertSame('owner', $reg->get('owner')?->name);
    }
}
