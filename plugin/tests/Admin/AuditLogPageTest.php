<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Admin\AuditLogPage;
use Specflux\AgentSafety\Plugin\Audit\AuditReader;
use wpdb;

/**
 * The audit viewer must render every decision value the chain can hold.
 * PHPUnit runs with failOnWarning, so an undefined-index notice on a row
 * shape the page did not expect fails the test on its own.
 */
final class AuditLogPageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_user_caps'] = ['manage_options' => true];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_user_caps'] = [];
    }

    public function testRendersAnAdminConfigurationRowWithItsOwnBadge(): void
    {
        $db = new wpdb();
        $db->varReturn = 1;
        $db->resultsReturn = [[
            'id' => 1,
            'event_id' => 'evt_1',
            'ts' => '2026-01-01T00:00:00Z',
            'correlation_id' => 'sess_1',
            'pack' => 'admin',
            'ability' => 'agsafe_shadow_packs',
            'tier' => null,
            'decision' => 'admin',
            'result' => null,
            'wp_user' => 7,
            'ip' => null,
            'record_json' => '{"reason":"shadow.enabled","input":{"pack":"owner","expires_at":1800000000}}',
            'prev_hash' => str_repeat('0', 64),
            'entry_hash' => str_repeat('0', 64),
        ]];

        ob_start();
        (new AuditLogPage(new AuditReader($db)))->render();
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('background:#50575e', $out, 'admin rows get their own colour, not the unknown-value grey');
        $this->assertStringContainsString('>admin</span>', $out);
        $this->assertStringContainsString('shadow.enabled', $out);
        $this->assertStringContainsString('agsafe_shadow_packs', $out);
    }
}
