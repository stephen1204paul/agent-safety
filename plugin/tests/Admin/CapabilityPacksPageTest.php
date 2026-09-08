<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Admin\CapabilityPacksPage;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * The Packs screen's two saves are exercised through their apply*() seams
 * (no nonce, redirect or exit), against the built-in catalog (`owner`,
 * `default-agent`) and a frozen clock. What matters is that every change a
 * human makes here lands in the audit chain, and that nothing malformed can
 * shadow a pack for longer than the form offers.
 */
final class CapabilityPacksPageTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const DAY = 24 * 60 * 60;

    private InMemoryAuditSink $sink;
    private CapabilityPacksPage $page;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        remove_all_filters(ShadowMode::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
        $this->page = new CapabilityPacksPage(
            new PackResolver(),
            new IdentityChain(),
            new ShadowMode(),
            new AdminChangeRecorder($this->sink),
        );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        $GLOBALS['wpas_test_current_user_id'] = 0;
        $GLOBALS['wpas_test_user_caps'] = [];
        remove_all_filters(ShadowMode::FILTER);
        RequestContext::reset();
    }

    // --- shadow mode ---------------------------------------------------------

    public function testEnablingShadowStoresTheExpiryAndAuditsWhoDidIt(): void
    {
        $this->page->applyShadow(['default-agent'], 3);

        $expiresAt = self::NOW + 3 * self::DAY;
        $this->assertSame(['default-agent' => $expiresAt], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);

        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_SHADOW_ENABLED, $row['reason']);
        $this->assertSame('admin', $row['decision']);
        $this->assertSame(AdminChangeRecorder::PACK, $row['pack']);
        $this->assertSame(ShadowMode::OPTION, $row['ability']);
        $this->assertSame(['pack' => 'default-agent', 'expires_at' => $expiresAt], $row['input']);
        $this->assertSame(7, $row['actor']['wp_user'], 'the logged-in administrator is the actor');
        $this->assertNull($row['tier']);
        $this->assertNull($row['approval']);
    }

    public function testDisablingShadowAuditsTheRemoval(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['default-agent' => self::NOW + 100];

        $this->page->applyShadow([], 7);

        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_SHADOW_DISABLED, $row['reason']);
        $this->assertSame(['pack' => 'default-agent'], $row['input']);
    }

    public function testResavingAnUnchangedShadowFormWritesNothing(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['default-agent' => self::NOW + 100];

        $this->page->applyShadow(['default-agent'], 7);

        $this->assertSame(['default-agent' => self::NOW + 100], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame([], $this->sink->records);
    }

    public function testAPackNameOutsideTheCatalogIsNeitherStoredNorAudited(): void
    {
        $this->page->applyShadow(['not-a-pack', '', 'owner '], 7);

        // Whitespace is sanitised away; the unknown and empty names are dropped.
        $this->assertSame(['owner' => self::NOW + 7 * self::DAY], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertCount(1, $this->sink->records);
        $this->assertSame(['pack' => 'owner', 'expires_at' => self::NOW + 7 * self::DAY], $this->sink->records[0]->toArray()['input']);
    }

    public function testSevenDaysIsTheLongestTheFormCanBuy(): void
    {
        $this->page->applyShadow(['owner'], 7);

        $this->assertSame(['owner' => self::NOW + ShadowMode::MAX_TTL], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    public function testADurationTheFormDoesNotOfferBuysTheShortestOne(): void
    {
        $this->page->applyShadow(['owner'], 30);

        $this->assertSame(['owner' => self::NOW + self::DAY], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);

        $this->page->applyShadow(['owner', 'default-agent'], 0);

        $this->assertSame(
            ['owner' => self::NOW + self::DAY, 'default-agent' => self::NOW + self::DAY],
            $GLOBALS['wpas_test_options'][ShadowMode::OPTION],
        );
    }

    public function testALapsedEntryTheSaveSweepsUpIsAuditedAsExpiredNotDisabled(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['owner' => self::NOW - 1];

        $this->page->applyShadow(['default-agent'], 1);

        $reasons = array_map(static fn ($r): string => (string) $r->toArray()['reason'], $this->sink->records);
        $this->assertSame([AdminChangeRecorder::EVENT_SHADOW_EXPIRED, AdminChangeRecorder::EVENT_SHADOW_ENABLED], $reasons);
        $this->assertSame(['pack' => 'owner', 'expires_at' => self::NOW - 1], $this->sink->records[0]->toArray()['input']);
    }

    public function testRenderShowsWhenAShadowedPackExpiresAndOffersTheDurations(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['owner' => self::NOW + 3600];

        ob_start();
        $this->page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('expires ' . gmdate('Y-m-d H:i', self::NOW + 3600) . ' UTC', $out);
        $this->assertStringContainsString('value="owner" checked', $out);
        $this->assertStringContainsString('name="shadow_days"', $out);
        $this->assertStringContainsString('<option value="7" selected', $out);
        $this->assertStringContainsString('<option value="1">1 day</option>', $out);
    }

    public function testAFilterOnlyShadowIsNotedNotTickedSoAnUntouchedSaveMintsNothing(): void
    {
        add_filter(ShadowMode::FILTER, static fn (array $packs): array => $packs + ['owner' => self::NOW + 3600]);

        ob_start();
        $this->page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('value="owner"> audit only', $out, 'not ticked');
        $this->assertStringContainsString(
            'shadowed by the ' . ShadowMode::FILTER . ' filter until ' . gmdate('Y-m-d H:i', self::NOW + 3600) . ' UTC',
            $out,
        );

        // Saving the form as rendered (nothing ticked) stores and audits nothing.
        $this->page->applyShadow([], 7);

        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame([], $this->sink->records);
        $this->assertTrue((new ShadowMode())->isShadow('owner'), 'the filter still shadows it');
    }

    // --- bindings ------------------------------------------------------------

    public function testChangingBindingsAuditsOneRowPerSubjectThatChanged(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = [
            'app:1' => 'default-agent',
            'user:2' => 'owner',
        ];

        $this->page->applyBindings([
            'app:1' => 'owner',
            'user:2' => 'owner',
            'role:editor' => 'default-agent',
        ]);

        $this->assertSame(
            ['app:1' => 'owner', 'user:2' => 'owner', 'role:editor' => 'default-agent'],
            $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION],
        );

        $this->assertCount(2, $this->sink->records);
        $inputs = [];
        foreach ($this->sink->records as $record) {
            $row = $record->toArray();
            $this->assertSame(AdminChangeRecorder::EVENT_BINDING_CHANGED, $row['reason']);
            $this->assertSame('admin', $row['decision']);
            $this->assertSame(AdminChangeRecorder::PACK, $row['pack']);
            $this->assertSame(PackResolver::BINDINGS_OPTION, $row['ability']);
            $this->assertSame(7, $row['actor']['wp_user']);
            $inputs[$row['input']['subject']] = $row['input'];
        }
        $this->assertSame(['subject' => 'app:1', 'from' => 'default-agent', 'to' => 'owner'], $inputs['app:1']);
        $this->assertSame(['subject' => 'role:editor', 'from' => null, 'to' => 'default-agent'], $inputs['role:editor']);
    }

    public function testDroppingABindingBackToTheDefaultIsAChange(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:1' => 'owner'];

        $this->page->applyBindings(['app:1' => '']);

        $this->assertSame([], $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION]);
        $this->assertCount(1, $this->sink->records);
        $this->assertSame(
            ['subject' => 'app:1', 'from' => 'owner', 'to' => null],
            $this->sink->records[0]->toArray()['input'],
        );
    }

    public function testAPackOutsideTheCatalogIsNotBoundAndTheFallbackIsAudited(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:1' => 'owner'];

        $this->page->applyBindings(['app:1' => 'not-a-pack']);

        $this->assertSame([], $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION]);
        $this->assertSame(
            ['subject' => 'app:1', 'from' => 'owner', 'to' => null],
            $this->sink->records[0]->toArray()['input'],
        );
    }

    public function testResavingUnchangedBindingsWritesNothing(): void
    {
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:1' => 'owner'];

        $this->page->applyBindings(['app:1' => 'owner']);

        $this->assertSame([], $this->sink->records);
    }

    public function testTheDiffIsAgainstTheStoredOptionNotAFilteredRegistry(): void
    {
        // A registry filter that swaps bindings must not make the audit row
        // describe a "from" the option never held.
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['app:1' => 'default-agent'];
        add_filter('agent_safety_pack_registry', static function ($registry) {
            return \Specflux\AgentSafety\Packs\PackRegistry::withBuiltins(['app:1' => 'owner']);
        });

        try {
            $this->page->applyBindings(['app:1' => 'owner']);
        } finally {
            remove_all_filters('agent_safety_pack_registry');
        }

        $this->assertCount(1, $this->sink->records);
        $this->assertSame(
            ['subject' => 'app:1', 'from' => 'default-agent', 'to' => 'owner'],
            $this->sink->records[0]->toArray()['input'],
        );
    }

    public function testWithoutASinkTheSavesStillPersist(): void
    {
        $page = new CapabilityPacksPage(new PackResolver(), new IdentityChain());

        $page->applyShadow(['owner'], 1);
        $page->applyBindings(['app:1' => 'owner']);

        $this->assertSame(['owner' => self::NOW + self::DAY], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame(['app:1' => 'owner'], $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION]);
    }
}
