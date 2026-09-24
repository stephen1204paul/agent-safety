<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Core;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Integrations\Core\CorePacks;
use Specflux\AgentSafety\Plugin\Integrations\Core\CoreVerbCatalog;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooPacks;
use Specflux\AgentSafety\Policy\Tier;

/**
 * The core starter preset is POLICY shipped as code, pinned by the same
 * safety-critical property style as {@see WooPacksTest}: what the pack can
 * NEVER reach.
 */
final class CorePacksTest extends TestCase
{
    public function testAllReturnsOnlySiteReadonly(): void
    {
        $names = array_map(static fn (Pack $p): string => $p->name, CorePacks::all());

        $this->assertSame(['site-readonly'], $names);
    }

    public function testSiteReadonlyDeniesEveryWriteClass(): void
    {
        $pack = $this->pack('site-readonly');

        // The three live reads are reachable...
        foreach ($this->readVerbs() as $verb) {
            $this->assertTrue($pack->allows($verb), $verb);
        }

        // ...and both write classes are hard-walled, so a read-looking verb
        // that writes still can't slip through.
        $this->assertTrue($pack->deniesClass(Tier::SideEffecting));
        $this->assertTrue($pack->deniesClass(Tier::Irreversible));
    }

    public function testPresetNameDoesNotCollideWithWoo(): void
    {
        $coreNames = array_map(static fn (Pack $p): string => $p->name, CorePacks::all());
        $wooNames = array_map(static fn (Pack $p): string => $p->name, WooPacks::all());

        // PackRegistry::register() overwrites silently by name, so a collision
        // would make Woo's registration clobber a core preset (or vice versa)
        // depending on module order — neither may shadow the other.
        $this->assertSame([], array_intersect($coreNames, $wooNames));
    }

    /** @return list<string> */
    private function readVerbs(): array
    {
        return [
            CoreVerbCatalog::GET_SITE_INFO,
            CoreVerbCatalog::GET_ENVIRONMENT_INFO,
            CoreVerbCatalog::GET_USER_INFO,
        ];
    }

    private function pack(string $name): Pack
    {
        foreach (CorePacks::all() as $pack) {
            if ($pack->name === $name) {
                return $pack;
            }
        }

        self::fail(sprintf('preset "%s" not found', $name));
    }
}
