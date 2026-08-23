<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Core;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\ArgumentCapPolicy;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Integrations\Core\CorePacks;
use Specflux\AgentSafety\Plugin\Integrations\Core\CoreVerbCatalog;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooPacks;
use Specflux\AgentSafety\Policy\Tier;

/**
 * The core starter presets are POLICY shipped as code, pinned by the same
 * safety-critical property style as {@see WooPacksTest}: what each pack can
 * NEVER reach.
 */
final class CorePacksTest extends TestCase
{
    public function testSiteReadonlyDeniesEveryWriteClass(): void
    {
        $pack = $this->pack('site-readonly');

        // All six read verbs (live + proposed) are reachable...
        foreach ($this->readVerbs() as $verb) {
            $this->assertTrue($pack->allows($verb), $verb);
        }

        // ...every write verb is not, AND both write classes are hard-walled
        // so a read-looking verb that writes still can't slip through.
        $this->assertFalse($pack->allows(CoreVerbCatalog::MANAGE_CONTENT));
        $this->assertFalse($pack->allows(CoreVerbCatalog::MANAGE_USERS));
        $this->assertTrue($pack->deniesClass(Tier::SideEffecting));
        $this->assertTrue($pack->deniesClass(Tier::Irreversible));
    }

    public function testContentEditorRequiresApprovalForTier2(): void
    {
        $pack = $this->pack('content-editor');

        // Draft-level content edits ride the base tier with no approval...
        $this->assertTrue($pack->allows(CoreVerbCatalog::MANAGE_CONTENT));
        $this->assertFalse($pack->requiresApproval(Tier::SideEffecting));

        // ...but the elevated shapes (publish / bulk delete) — Tier 2 once
        // an elevation rule fires — need a human first. Other users/settings
        // management is unreachable BY CONSTRUCTION: not in the allow list.
        $this->assertTrue($pack->requiresApproval(Tier::Irreversible));
        $this->assertFalse($pack->allows(CoreVerbCatalog::MANAGE_SETTINGS));
        $this->assertFalse($pack->allows(CoreVerbCatalog::MANAGE_USERS));
    }

    public function testSiteAdminAgentCapsBulkItemsAt25(): void
    {
        $pack = $this->pack('site-admin-agent');

        // Full reach across all nine verbs; irreversible calls approval-gated.
        foreach ($this->readVerbs() as $verb) {
            $this->assertTrue($pack->allows($verb), $verb);
        }
        $this->assertTrue($pack->allows(CoreVerbCatalog::MANAGE_SETTINGS));
        $this->assertTrue($pack->allows(CoreVerbCatalog::MANAGE_USERS));
        $this->assertTrue($pack->requiresApproval(Tier::Irreversible));

        $this->assertTrue($pack->hasArgumentCaps());
        $cap = $pack->argumentCaps[0];
        $this->assertSame('core-bulk-items', $cap->id);

        // The cap's verb pattern is the EXACT manage-content id — NOT a
        // `core/manage-*` glob. ArgumentCapPolicy fails closed when argPath
        // is missing/malformed, so a glob spanning manage-settings/users
        // would deny every one of those calls outright (no `ids` list).
        $this->assertSame(CoreVerbCatalog::MANAGE_CONTENT, $cap->verbs);
        $this->assertTrue($cap->appliesTo(CoreVerbCatalog::MANAGE_CONTENT));
        $this->assertFalse($cap->appliesTo(CoreVerbCatalog::MANAGE_SETTINGS));
        $this->assertFalse($cap->appliesTo(CoreVerbCatalog::MANAGE_USERS));
        $this->assertSame(25, $cap->maxItemsPerCall);
        $this->assertNull($cap->approvalAbove);
    }

    public function testSiteAdminAgentDeniesManageContentWithoutIds(): void
    {
        // The flip-side of fail-closed, documented on CorePacks and D27:
        // under this preset a manage-content call WITHOUT ids (e.g. create)
        // is denied by the cap rather than running ungoverned — and a
        // 26-item bulk is denied while exactly 25 passes.
        $policy = new ArgumentCapPolicy();
        $pack = $this->pack('site-admin-agent');
        $cap = $pack->argumentCaps[0];

        $denied = $policy->evaluate([$cap], CoreVerbCatalog::MANAGE_CONTENT, ['status' => 'publish'], []);
        $this->assertFalse($denied->allowed);
        $this->assertSame('unreadable_argument', $denied->constraint);

        $overBulk = $policy->evaluate([$cap], CoreVerbCatalog::MANAGE_CONTENT, ['ids' => range(1, 26)], []);
        $this->assertFalse($overBulk->allowed);
        $this->assertSame('max_items_per_call', $overBulk->constraint);

        $atBulk = $policy->evaluate([$cap], CoreVerbCatalog::MANAGE_CONTENT, ['ids' => range(1, 25)], []);
        $this->assertTrue($atBulk->allowed);
    }

    public function testPresetNamesDoNotCollideWithWoo(): void
    {
        $coreNames = array_map(static fn (Pack $p): string => $p->name, CorePacks::all());
        $wooNames = array_map(static fn (Pack $p): string => $p->name, WooPacks::all());

        // PackRegistry::register() overwrites silently by name, so a collision
        // would make Woo's registration clobber a core preset (or vice versa)
        // depending on module order — neither may shadow the other.
        $this->assertSame([], array_intersect($coreNames, $wooNames));

        $this->assertSame(
            ['site-readonly', 'content-editor', 'site-admin-agent'],
            $coreNames,
        );
    }

    /** @return list<string> */
    private function readVerbs(): array
    {
        return [
            CoreVerbCatalog::GET_SITE_INFO,
            CoreVerbCatalog::GET_ENVIRONMENT_INFO,
            CoreVerbCatalog::GET_USER_INFO,
            CoreVerbCatalog::READ_SETTINGS,
            CoreVerbCatalog::READ_CONTENT,
            CoreVerbCatalog::READ_USERS,
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
