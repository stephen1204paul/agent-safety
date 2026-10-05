<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Specflux\AgentSafety\Plugin\Support\MultisiteGuard;

final class MultisiteGuardTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wpas_test_multisite'],
            $GLOBALS['wpas_test_deactivated_plugins'],
            $GLOBALS['wpas_test_added_actions']
        );
    }

    public function testSingleSiteIsNotRefused(): void
    {
        $GLOBALS['wpas_test_multisite'] = false;

        $this->assertFalse(MultisiteGuard::refused());

        MultisiteGuard::refuseActivation('/plugins/agent-tollgate/agent-tollgate.php');
        MultisiteGuard::refuseRuntime('/plugins/agent-tollgate/agent-tollgate.php');

        $this->assertSame([], $GLOBALS['wpas_test_deactivated_plugins'] ?? []);
    }

    public function testMultisiteActivationDeactivatesAndDiesWithTheRefusal(): void
    {
        $GLOBALS['wpas_test_multisite'] = true;

        $this->assertTrue(MultisiteGuard::refused());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support WordPress multisite');

        try {
            MultisiteGuard::refuseActivation('/plugins/agent-tollgate/agent-tollgate.php');
        } finally {
            $this->assertSame(
                ['agent-tollgate/agent-tollgate.php'],
                $GLOBALS['wpas_test_deactivated_plugins'] ?? []
            );
        }
    }

    public function testMultisiteActivationRefusesNetworkActivationTheSameWay(): void
    {
        // Network-wide activation carries no distinct signal here: is_multisite()
        // is already true the moment a network exists, per-site or network-wide,
        // so one code path covers both.
        $GLOBALS['wpas_test_multisite'] = true;

        $this->expectException(RuntimeException::class);

        MultisiteGuard::refuseActivation('/plugins/agent-tollgate/agent-tollgate.php');
    }

    public function testMultisiteRuntimeGuardDeactivatesAndShowsAnAdminNotice(): void
    {
        $GLOBALS['wpas_test_multisite'] = true;

        MultisiteGuard::refuseRuntime('/plugins/agent-tollgate/agent-tollgate.php');

        $this->assertSame(
            ['agent-tollgate/agent-tollgate.php'],
            $GLOBALS['wpas_test_deactivated_plugins'] ?? []
        );
        $this->assertNotEmpty($GLOBALS['wpas_test_added_actions']['admin_notices'] ?? []);
    }

    public function testRuntimeNoticeNamesMultisite(): void
    {
        ob_start();
        MultisiteGuard::renderNotice();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('multisite is not supported', $html);
    }
}
