<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\ApprovalSweep;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Support\Schema;
use Specflux\AgentSafety\Plugin\Support\UninstallManifest;

/**
 * §3.6 item 3: `plugin/uninstall.php` is standalone (no bootstrap
 * guaranteed) and gated on `AGSAFE_REMOVE_DATA === true` — both the constant
 * check and `WP_UNINSTALL_PLUGIN` are real PHP constants, so each scenario
 * below runs in its OWN process ({@see RunInSeparateProcess}); a constant
 * defined for one test would otherwise leak into every other test in this
 * class (and, since PHPUnit reuses one process per file by default, into the
 * rest of the suite).
 */
final class UninstallTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testOptedInUninstallDropsTablesDeletesEveryManifestOptionAndClearsCron(): void
    {
        define('WP_UNINSTALL_PLUGIN', 'agent-safety/agent-safety.php');
        define('AGSAFE_REMOVE_DATA', true);

        $GLOBALS['wpas_test_options'] = [
            Schema::VERSION_OPTION => '4',
            PauseSwitch::OPTION => ['since' => 1, 'by' => null, 'reason' => 'testing'],
            // A live site's options table holds plenty this plugin never wrote — uninstall must
            // leave them alone; admin_email standing in as WordPress core's own.
            'admin_email' => 'owner@example.com',
        ];
        $GLOBALS['wpas_test_cron'] = [ApprovalSweep::HOOK => 1_800_000_000];

        $wpdb = $GLOBALS['wpdb'] = new \wpdb();

        require __DIR__ . '/../uninstall.php';

        foreach (UninstallManifest::TABLE_BASENAMES as $base) {
            $this->assertContains(
                "DROP TABLE IF EXISTS `{$wpdb->prefix}{$base}`",
                $wpdb->queries,
                "Expected an opted-in uninstall to drop {$base}",
            );
        }

        foreach (UninstallManifest::OPTIONS as $option) {
            $this->assertArrayNotHasKey($option, $GLOBALS['wpas_test_options'], "Expected {$option} to be deleted");
        }

        $this->assertArrayNotHasKey(ApprovalSweep::HOOK, $GLOBALS['wpas_test_cron'], 'Expected the sweep cron to be cleared');

        $this->assertSame(
            'owner@example.com',
            $GLOBALS['wpas_test_options']['admin_email'],
            'A WordPress-core option this plugin merely reads must never be deleted',
        );

        $transientDeletes = array_values(array_filter(
            $wpdb->queries,
            static fn (string $q): bool => str_starts_with($q, 'DELETE FROM'),
        ));
        $this->assertCount(
            count(UninstallManifest::TRANSIENT_PREFIXES),
            $transientDeletes,
            'Expected exactly one transient-sweep DELETE per manifest prefix',
        );
        foreach (UninstallManifest::TRANSIENT_PREFIXES as $prefix) {
            $escaped = $wpdb->esc_like($prefix);
            $matching = array_filter(
                $transientDeletes,
                static fn (string $q): bool => str_contains($q, $escaped),
            );
            $this->assertNotEmpty($matching, "Expected a transient DELETE covering prefix {$prefix}");
        }
    }

    #[RunInSeparateProcess]
    public function testUninstallIsANoOpWithoutTheOptInConstant(): void
    {
        define('WP_UNINSTALL_PLUGIN', 'agent-safety/agent-safety.php');
        // AGSAFE_REMOVE_DATA left undefined -- the default, "keep everything" behaviour.

        $GLOBALS['wpas_test_options'] = [Schema::VERSION_OPTION => '4'];
        $GLOBALS['wpas_test_cron'] = [ApprovalSweep::HOOK => 1_800_000_000];

        $wpdb = $GLOBALS['wpdb'] = new \wpdb();

        require __DIR__ . '/../uninstall.php';

        $this->assertSame([], $wpdb->queries, 'Expected no SQL at all without the opt-in constant');
        $this->assertSame('4', $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertArrayHasKey(ApprovalSweep::HOOK, $GLOBALS['wpas_test_cron']);
    }

    #[RunInSeparateProcess]
    public function testUninstallIsANoOpWhenTheOptInConstantIsNotExactlyTrue(): void
    {
        define('WP_UNINSTALL_PLUGIN', 'agent-safety/agent-safety.php');
        define('AGSAFE_REMOVE_DATA', 1); // truthy, but not the required strict `true`

        $GLOBALS['wpas_test_options'] = [Schema::VERSION_OPTION => '4'];

        $wpdb = $GLOBALS['wpdb'] = new \wpdb();

        require __DIR__ . '/../uninstall.php';

        $this->assertSame([], $wpdb->queries);
        $this->assertSame('4', $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }
}
