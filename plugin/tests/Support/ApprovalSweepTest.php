<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Support\ApprovalSweep;
use wpdb;

final class ApprovalSweepTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_cron'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_cron'] = [];
    }

    public function testActivateSchedulesTheHookHourlyWhenNotAlreadyScheduled(): void
    {
        ApprovalSweep::activate();

        $this->assertArrayHasKey(ApprovalSweep::HOOK, $GLOBALS['wpas_test_cron']);
    }

    public function testActivateIsIdempotentWhenAlreadyScheduled(): void
    {
        $GLOBALS['wpas_test_cron'][ApprovalSweep::HOOK] = 12345;

        ApprovalSweep::activate();

        // Untouched: a fresh wp_schedule_event() call would have overwritten
        // this with time(), so the original stamp surviving proves the
        // wp_next_scheduled() guard actually short-circuited.
        $this->assertSame(12345, $GLOBALS['wpas_test_cron'][ApprovalSweep::HOOK]);
    }

    public function testDeactivateClearsTheSchedule(): void
    {
        $GLOBALS['wpas_test_cron'][ApprovalSweep::HOOK] = time();

        ApprovalSweep::deactivate();

        $this->assertArrayNotHasKey(ApprovalSweep::HOOK, $GLOBALS['wpas_test_cron']);
    }

    public function testDeactivateWithoutAPriorScheduleIsAHarmlessNoOp(): void
    {
        ApprovalSweep::deactivate();

        $this->assertArrayNotHasKey(ApprovalSweep::HOOK, $GLOBALS['wpas_test_cron']);
    }

    public function testRunSweepsTheStoreWithTheCurrentTime(): void
    {
        $db = new wpdb();
        $db->queryReturn = 2;
        $store = new WpdbApprovalStore($db);

        ApprovalSweep::run($store);

        $deletes = array_values(array_filter($db->queries, static fn (string $q): bool => str_starts_with($q, 'DELETE')));
        $this->assertCount(1, $deletes);
        $this->assertStringContainsString(gmdate('Y-m-d H:i'), $deletes[0]);
    }
}
