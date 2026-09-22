<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Specflux\AgentSafety\Plugin\Admin\CapabilityPacksPage;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * The emergency stop on the Packs screen: pauseAction()/resumeAction() are
 * exercised only on their refusal paths (both end in wp_safe_redirect() +
 * exit, unreachable under the test shims), success is proven through
 * applyPause()/applyResume(), and the render side (renderEmergencyStop(),
 * pausedNotice()) is proven by output. What a pause does to a governed call
 * is proven in {@see \Specflux\AgentSafety\Plugin\Tests\Verdict\VerdictPipelineStopsTest};
 * the switch itself in {@see \Specflux\AgentSafety\Plugin\Tests\Support\PauseSwitchTest}.
 */
final class CapabilityPacksPageEmergencyStopTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryAuditSink $sink;
    private PauseSwitch $pause;
    private CapabilityPacksPage $page;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
        $_POST = [];
        $_GET = [];
        remove_all_filters(ShadowMode::FILTER);
        remove_all_filters(PauseSwitch::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
        $this->pause = new PauseSwitch(new AdminChangeRecorder($this->sink));
        $this->page = new CapabilityPacksPage(
            new PackResolver(),
            new IdentityChain(),
            new ShadowMode(),
            new AdminChangeRecorder($this->sink),
            $this->pause,
        );
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        $GLOBALS['wpas_test_current_user_id'] = 0;
        $GLOBALS['wpas_test_user_caps'] = [];
        $_POST = [];
        $_GET = [];
        remove_all_filters(ShadowMode::FILTER);
        remove_all_filters(PauseSwitch::FILTER);
        RequestContext::reset();
    }

    // --- pauseAction() refusals -----------------------------------------------

    public function testPauseActionWithoutTheCapabilityDiesAndPausesNothing(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];
        $_POST = ['_wpnonce' => 'test-nonce', 'reason' => 'runaway bot'];

        try {
            $this->page->pauseAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertFalse($this->pause->isPaused());
        $this->assertNull($this->pause->state());
        $this->assertSame([], $this->sink->records, 'a refused pause audits nothing');
    }

    public function testPauseActionWithMissingNonceDiesAndPausesNothing(): void
    {
        $_POST = ['reason' => 'runaway bot']; // no _wpnonce at all

        try {
            $this->page->pauseAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertFalse($this->pause->isPaused());
        $this->assertSame([], $this->sink->records);
    }

    public function testPauseActionWithWrongNonceDiesAndPausesNothing(): void
    {
        $_POST = ['_wpnonce' => 'not-the-right-nonce', 'reason' => 'runaway bot'];

        try {
            $this->page->pauseAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertFalse($this->pause->isPaused());
        $this->assertSame([], $this->sink->records);
    }

    // --- resumeAction() refusals: a stored pause stays in place ---------------

    public function testResumeActionWithoutTheCapabilityDiesAndLeavesTheStoredPauseInPlace(): void
    {
        $this->pause->pause(7, 'runaway bot');
        $this->sink->records = []; // isolate the resume attempt from the setup pause
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];
        $_POST = ['_wpnonce' => 'test-nonce'];

        try {
            $this->page->resumeAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertTrue($this->pause->isPaused());
        $this->assertNotNull($this->pause->state());
        $this->assertSame([], $this->sink->records, 'a refused resume audits nothing');
    }

    public function testResumeActionWithMissingOrWrongNonceDiesAndLeavesTheStoredPauseInPlace(): void
    {
        $this->pause->pause(7, 'runaway bot');
        $this->sink->records = [];
        $_POST = ['_wpnonce' => 'nope'];

        try {
            $this->page->resumeAction();
            $this->fail('expected wp_die to throw');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('wp_die:', $e->getMessage());
        }

        $this->assertTrue($this->pause->isPaused());
        $this->assertSame([], $this->sink->records);
    }

    // --- applyPause() / applyResume(): the success paths -----------------------

    public function testApplyPauseStoresTheReasonAndPausesAsTheLoggedInUser(): void
    {
        $this->page->applyPause('runaway fulfilment bot');

        $stored = ['since' => self::NOW, 'by' => 7, 'reason' => 'runaway fulfilment bot'];
        $this->assertSame($stored, $GLOBALS['wpas_test_options'][PauseSwitch::OPTION]);
        $this->assertTrue($this->pause->isPaused());

        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_PAUSE_ENABLED, $row['reason']);
        $this->assertSame(7, $row['actor']['wp_user']);
    }

    public function testApplyResumeClearsAStoredPauseAsTheLoggedInUser(): void
    {
        $this->pause->pause(7, 'x');
        $this->sink->records = [];
        $GLOBALS['wpas_test_current_user_id'] = 9;

        $this->page->applyResume();

        $this->assertArrayNotHasKey(PauseSwitch::OPTION, $GLOBALS['wpas_test_options']);
        $this->assertFalse($this->pause->isPaused());
        $this->assertCount(1, $this->sink->records);
        $this->assertSame(9, $this->sink->records[0]->toArray()['actor']['wp_user']);
    }

    // --- renderEmergencyStop() / pausedNotice(): output -------------------------

    public function testPausedNoticeIsSilentForAUserWithoutTheCapability(): void
    {
        $this->pause->pause(7, 'x');
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => false];

        ob_start();
        $this->page->pausedNotice();
        $out = (string) ob_get_clean();

        $this->assertSame('', $out);
    }

    public function testPausedNoticeIsSilentWhenNotPaused(): void
    {
        ob_start();
        $this->page->pausedNotice();
        $out = (string) ob_get_clean();

        $this->assertSame('', $out);
    }

    public function testPausedNoticeShowsForACapableUserWhenPaused(): void
    {
        $this->pause->pause(7, 'x');

        ob_start();
        $this->page->pausedNotice();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('Agent Safety: all agent actions are paused.', $out);
        $this->assertStringContainsString('Review or resume', $out);
    }

    public function testRenderShowsNoPauseBannerWhenNotPaused(): void
    {
        ob_start();
        $this->page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('Emergency stop', $out);
        $this->assertStringNotContainsString('All agent actions are paused.', $out);
        $this->assertStringContainsString('Pause all agent actions', $out);
    }

    public function testRenderShowsTheBannerAndResumeFormForAStoredPause(): void
    {
        $this->pause->pause(7, 'runaway bot');

        ob_start();
        $this->page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('All agent actions are paused.', $out);
        $this->assertStringContainsString('Since ' . gmdate('Y-m-d H:i', self::NOW) . ' UTC by user #7. Reason: runaway bot', $out);
        $this->assertStringContainsString('value="agsafe_resume"', $out);
    }

    public function testRenderShowsTheBannerButNoResumeFormForAFilterForcedPause(): void
    {
        add_filter(PauseSwitch::FILTER, static fn (): bool => true);

        ob_start();
        $this->page->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('All agent actions are paused.', $out);
        $this->assertStringContainsString('Forced by the ' . PauseSwitch::FILTER . ' filter', $out);
        $this->assertStringNotContainsString('value="agsafe_resume"', $out);
        $this->assertStringNotContainsString('Resume agent actions', $out);
    }

    public function testRenderEscapesAnInjectedReasonRatherThanEmittingItRaw(): void
    {
        $this->pause->pause(7, '<script>alert(1)</script>');

        ob_start();
        $this->page->render();
        $out = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }
}
