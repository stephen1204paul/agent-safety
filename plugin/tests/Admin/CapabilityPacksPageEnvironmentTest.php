<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Specflux\AgentSafety\Plugin\Admin\CapabilityPacksPage;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\EnvironmentGuard;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * AS-7 §3.4 item 7: rebind is `manage_options` + nonce, restores nothing, and
 * (item 9) enabling shadow on production requires typing the pack name back.
 */
final class CapabilityPacksPageEnvironmentTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryAuditSink $sink;
    private EnvironmentGuard $environment;
    private CapabilityPacksPage $page;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $GLOBALS['wpas_test_home_url'] = 'https://new-host.example';
        unset($GLOBALS['wpas_test_environment_type']);
        $_POST = [];
        $_GET = [];
        remove_all_filters(ShadowMode::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
        $this->environment = new EnvironmentGuard(new ShadowMode(), new AdminChangeRecorder($this->sink));
        $this->page = new CapabilityPacksPage(
            new PackResolver(),
            new IdentityChain(),
            new ShadowMode(),
            new AdminChangeRecorder($this->sink),
            environment: $this->environment,
        );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        $GLOBALS['wpas_test_current_user_id'] = 0;
        $GLOBALS['wpas_test_user_caps'] = [];
        unset($GLOBALS['wpas_test_home_url'], $GLOBALS['wpas_test_environment_type']);
        $_POST = [];
        $_GET = [];
        remove_all_filters(ShadowMode::FILTER);
        RequestContext::reset();
    }

    // --- rebindAction() refusals -----------------------------------------------

    public function testRebindActionWithoutTheCapabilityDiesAndRebindsNothing(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];
        $_POST = ['_wpnonce' => 'test-nonce'];

        try {
            $this->page->rebindAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertSame('old-host.example', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
        $this->assertSame([], $this->sink->records, 'a refused rebind audits nothing');
    }

    public function testRebindActionWithAWrongNonceDiesAndRebindsNothing(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $_POST = ['_wpnonce' => 'not-the-right-nonce'];

        try {
            $this->page->rebindAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertSame('old-host.example', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
        $this->assertSame([], $this->sink->records);
    }

    // --- direct method (success, no nonce/redirect/exit) ------------------------

    public function testRebindOnTheGuardBindsToTheCurrentHostAndRestoresNoVoidedState(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = []; // already voided by an earlier mismatch

        $this->environment->rebind();

        $this->assertSame('new-host.example', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION], 'rebind must not restore the voided shadow set');
        $this->assertFalse($this->environment->isMismatched());
    }

    // --- production shadow confirmation (item 9) --------------------------------

    public function testEnablingShadowOnProductionWithoutTypingThePackNameIsDropped(): void
    {
        $GLOBALS['wpas_test_environment_type'] = 'production';

        $this->page->applyShadow(['default-agent'], 7, []);

        $this->assertArrayNotHasKey('default-agent', $GLOBALS['wpas_test_options'][ShadowMode::OPTION] ?? []);
        $this->assertSame([], $this->sink->records, 'nothing was enabled, so nothing is audited');
    }

    public function testEnablingShadowOnProductionWithAWrongTypedNameIsDropped(): void
    {
        $GLOBALS['wpas_test_environment_type'] = 'production';

        $this->page->applyShadow(['default-agent'], 7, ['default-agent' => 'not-the-pack-name']);

        $this->assertArrayNotHasKey('default-agent', $GLOBALS['wpas_test_options'][ShadowMode::OPTION] ?? []);
    }

    public function testEnablingShadowOnProductionWithTheCorrectTypedNameSucceedsAndIsCappedAt24h(): void
    {
        $GLOBALS['wpas_test_environment_type'] = 'production';

        $this->page->applyShadow(['default-agent'], 7, ['default-agent' => 'default-agent']);

        $this->assertSame(
            self::NOW + ShadowMode::PRODUCTION_MAX_TTL,
            $GLOBALS['wpas_test_options'][ShadowMode::OPTION]['default-agent'],
        );
        $this->assertCount(1, $this->sink->records);
        $this->assertSame(AdminChangeRecorder::EVENT_SHADOW_ENABLED, $this->sink->records[0]->reason);
    }

    public function testAnAlreadyShadowedPackNeedsNoRetypingOnProduction(): void
    {
        $GLOBALS['wpas_test_environment_type'] = 'production';
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['default-agent' => self::NOW + 3600];

        // Re-saving the form with the box still ticked, no confirmation typed.
        $this->page->applyShadow(['default-agent'], 7, []);

        $this->assertSame(self::NOW + 3600, $GLOBALS['wpas_test_options'][ShadowMode::OPTION]['default-agent'], 'unchanged, not dropped');
    }

    public function testRenewShadowActionRequiresTheCapabilityAndNonce(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['default-agent' => self::NOW + 60];
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];
        $_POST = ['_wpnonce' => 'test-nonce', 'pack' => 'default-agent'];

        try {
            $this->page->renewShadowAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertSame(self::NOW + 60, $GLOBALS['wpas_test_options'][ShadowMode::OPTION]['default-agent']);
    }

    public function testApplyRenewShadowExtendsAnAlreadyShadowedPack(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['default-agent' => self::NOW + 60];

        $this->page->applyRenewShadow('default-agent');

        $this->assertSame(self::NOW + ShadowMode::MAX_TTL, $GLOBALS['wpas_test_options'][ShadowMode::OPTION]['default-agent']);
        $this->assertCount(1, $this->sink->records);
        $this->assertSame(AdminChangeRecorder::EVENT_SHADOW_ENABLED, $this->sink->records[0]->reason);
    }

    public function testApplyRenewShadowIgnoresAnUnknownPackName(): void
    {
        $this->page->applyRenewShadow('not-a-real-pack');

        $this->assertSame([], $this->sink->records);
    }

    /** §3.4 item 9 / security fix: renew must not be usable to enable shadow on a never-shadowed pack. */
    public function testApplyRenewShadowDoesNothingForAPackWithNoShadowEntry(): void
    {
        $GLOBALS['wpas_test_environment_type'] = 'production';
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = [];

        $this->page->applyRenewShadow('default-agent');

        $this->assertArrayNotHasKey('default-agent', $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame([], $this->sink->records);
    }
}
