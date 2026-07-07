<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\Schema;
use wpdb;

/**
 * Exercises the version bookkeeping around dbDelta() install/upgrade —
 * dbDelta itself is shimmed (see tests/bootstrap.php) to just record the
 * statements it was handed, since diffing against a real database is out of
 * scope for this suite.
 */
final class SchemaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
    }

    public function testInstallStoresTheCurrentSchemaVersion(): void
    {
        Schema::install(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testInstallHandsBothTableStatementsToDbDelta(): void
    {
        Schema::install(new wpdb());

        $this->assertCount(2, $GLOBALS['wpas_test_dbdelta_queries']);
        $this->assertStringContainsString('wp_agsafe_audit_log', $GLOBALS['wpas_test_dbdelta_queries'][0]);
        $this->assertStringContainsString('wp_agsafe_approvals', $GLOBALS['wpas_test_dbdelta_queries'][1]);
    }

    public function testInstallStatementsNeverIncludeIfNotExists(): void
    {
        // dbDelta parses `CREATE TABLE {name}` with a regex that breaks if the
        // statement carries an `IF NOT EXISTS` clause (it would capture "IF" as
        // the table name) — that clause belongs ONLY to the lazy fallbacks.
        Schema::install(new wpdb());

        foreach ($GLOBALS['wpas_test_dbdelta_queries'] as $query) {
            $this->assertStringNotContainsStringIgnoringCase('IF NOT EXISTS', $query);
        }
    }

    public function testMaybeUpgradeNoOpsWhenTheStoredVersionIsCurrent(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = Schema::VERSION;

        Schema::maybeUpgrade(new wpdb());

        $this->assertSame([], $GLOBALS['wpas_test_dbdelta_queries']);
    }

    public function testMaybeUpgradeReinstallsWhenTheStoredVersionIsBehind(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '0';

        Schema::maybeUpgrade(new wpdb());

        $this->assertCount(2, $GLOBALS['wpas_test_dbdelta_queries']);
        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testMaybeUpgradeReinstallsWhenNoVersionWasEverStored(): void
    {
        Schema::maybeUpgrade(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }
}
