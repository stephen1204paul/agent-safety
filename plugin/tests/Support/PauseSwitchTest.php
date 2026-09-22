<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * The switch itself: what the option and the filter each can and cannot do,
 * and the audit row every throw and lift leaves. What a pause does to a
 * governed call is proven in {@see \Specflux\AgentSafety\Plugin\Tests\Verdict\VerdictPipelineStopsTest}.
 */
final class PauseSwitchTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryAuditSink $sink;
    private PauseSwitch $pause;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        $GLOBALS['wpas_test_current_user_id'] = 7;
        remove_all_filters(PauseSwitch::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
        $this->pause = new PauseSwitch(new AdminChangeRecorder($this->sink));
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        $GLOBALS['wpas_test_current_user_id'] = 0;
        remove_all_filters(PauseSwitch::FILTER);
        RequestContext::reset();
    }

    public function testNotPausedUntilSomethingSaysSo(): void
    {
        $this->assertFalse($this->pause->isPaused());
        $this->assertNull($this->pause->state());
    }

    public function testPauseStoresWhoWhenAndWhyAndAuditsTheActor(): void
    {
        $this->pause->pause(7, 'runaway fulfilment bot');

        $stored = ['since' => self::NOW, 'by' => 7, 'reason' => 'runaway fulfilment bot'];
        $this->assertSame($stored, $GLOBALS['wpas_test_options'][PauseSwitch::OPTION]);
        $this->assertTrue($this->pause->isPaused());
        $this->assertSame($stored, $this->pause->state());

        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_PAUSE_ENABLED, $row['reason']);
        $this->assertSame('admin', $row['decision']);
        $this->assertSame(AdminChangeRecorder::PACK, $row['pack']);
        $this->assertSame(PauseSwitch::OPTION, $row['ability']);
        $this->assertSame(['by' => 7, 'reason' => 'runaway fulfilment bot'], $row['input']);
        $this->assertSame(7, $row['actor']['wp_user']);
        $this->assertNull($row['tier']);
    }

    public function testResumeClearsTheOptionAndAuditsWhoLiftedIt(): void
    {
        $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => self::NOW - 60, 'by' => 7, 'reason' => 'x'];
        $GLOBALS['wpas_test_current_user_id'] = 9;

        $this->pause->resume(9);

        $this->assertArrayNotHasKey(PauseSwitch::OPTION, $GLOBALS['wpas_test_options']);
        $this->assertFalse($this->pause->isPaused());
        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_PAUSE_DISABLED, $row['reason']);
        $this->assertSame(['by' => 9], $row['input']);
        $this->assertSame(9, $row['actor']['wp_user']);
    }

    public function testResumingAnUnpausedSiteChangesAndAuditsNothing(): void
    {
        $this->pause->resume(7);

        $this->assertSame([], $GLOBALS['wpas_test_options']);
        $this->assertSame([], $this->sink->records);
    }

    public function testAMalformedButNonEmptyStoredValueStillPauses(): void
    {
        $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => '2026', 'by' => 'me', 'nonsense' => true];

        $this->assertTrue($this->pause->isPaused(), 'a hand-edited option fails towards stopped');
        $this->assertSame(['since' => null, 'by' => null, 'reason' => ''], $this->pause->state());
    }

    public function testAnEmptyArrayFalseOrAScalarDoesNotPause(): void
    {
        foreach ([[], false, 'yes', 1] as $stored) {
            $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = $stored;

            $this->assertFalse($this->pause->isPaused(), var_export($stored, true));
            $this->assertNull($this->pause->state());
        }
    }

    public function testTheFilterForcesAPauseOnlyWithLiteralTrue(): void
    {
        add_filter(PauseSwitch::FILTER, static fn (): bool => true);
        $this->assertTrue($this->pause->isPaused());
        $this->assertNull($this->pause->state(), 'a forced pause is not a stored one');

        foreach (['yes', 1, 'true', [true]] as $truthy) {
            remove_all_filters(PauseSwitch::FILTER);
            add_filter(PauseSwitch::FILTER, static fn () => $truthy);

            $this->assertFalse($this->pause->isPaused(), var_export($truthy, true));
        }
    }

    public function testTheFilterCannotClearAStoredPause(): void
    {
        $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => self::NOW, 'by' => 7, 'reason' => 'x'];
        add_filter(PauseSwitch::FILTER, static fn (): bool => false);

        $this->assertTrue($this->pause->isPaused());
    }

    public function testResumeCannotLiftAFilterForcedPause(): void
    {
        add_filter(PauseSwitch::FILTER, static fn (): bool => true);

        $this->pause->resume(7);

        $this->assertTrue($this->pause->isPaused());
        $this->assertSame([], $this->sink->records, 'nothing was lifted, so nothing is audited');
    }
}
