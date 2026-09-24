<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\Schema;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use wpdb;

/**
 * Exercises the version bookkeeping around dbDelta() install/upgrade —
 * dbDelta itself is shimmed (see tests/bootstrap.php) to just record the
 * statements it was handed, since diffing against a real database is out of
 * scope for this suite.
 */
final class SchemaTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_dbdelta_queries'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    public function testInstallStoresTheCurrentSchemaVersion(): void
    {
        Schema::install(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testInstallHandsEveryTableStatementToDbDelta(): void
    {
        Schema::install(new wpdb());

        $this->assertCount(3, $GLOBALS['wpas_test_dbdelta_queries']);
        $this->assertStringContainsString('wp_agsafe_audit_log', $GLOBALS['wpas_test_dbdelta_queries'][0]);
        $this->assertStringContainsString('wp_agsafe_approvals', $GLOBALS['wpas_test_dbdelta_queries'][1]);
        $this->assertStringContainsString('wp_agsafe_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
    }

    public function testTheGrantsTableIndexesTheOnlyLookupTheGatePerforms(): void
    {
        Schema::install(new wpdb());

        $grants = $GLOBALS['wpas_test_dbdelta_queries'][2];
        $this->assertStringContainsString('KEY scope (correlation_id, verb, status)', $grants);
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

        $this->assertCount(3, $GLOBALS['wpas_test_dbdelta_queries']);
        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testMaybeUpgradeReinstallsWhenNoVersionWasEverStored(): void
    {
        Schema::maybeUpgrade(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    // --- version 3: the shadow option grew an expiry ---------------------------

    public function testUpgradingFromVersionTwoGivesALegacyShadowListAFullWindow(): void
    {
        // A site mid-observation must not be switched to enforcement by the
        // upgrade: each listed pack gets MAX_TTL from the moment of upgrade.
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '2';
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent', 'owner'];

        Schema::maybeUpgrade(new wpdb());

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertSame([
            'support-agent' => self::NOW + ShadowMode::MAX_TTL,
            'owner' => self::NOW + ShadowMode::MAX_TTL,
        ], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame(['support-agent', 'owner'], (new ShadowMode())->packs());
    }

    public function testActivationOnAnAlreadyMigratedOptionChangesNothing(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent' => self::NOW + 60];

        Schema::install(new wpdb());

        $this->assertSame(['support-agent' => self::NOW + 60], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    public function testACurrentVersionNeverTouchesTheShadowOption(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = Schema::VERSION;
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent'];

        Schema::maybeUpgrade(new wpdb());

        // Left as-is: the reader fails closed on the legacy shape, the sweep
        // retires it. Only an upgrade hands out a fresh window.
        $this->assertSame(['support-agent'], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    // --- version 4: fingerprint columns + grants table rename -------------

    public function testApprovalsGainTheFingerprintColumns(): void
    {
        Schema::install(new wpdb());

        $approvals = $GLOBALS['wpas_test_dbdelta_queries'][1];
        $this->assertStringContainsString('fingerprint CHAR(64) NULL', $approvals);
        $this->assertStringContainsString('fingerprint_kind VARCHAR(10) NULL', $approvals);
    }

    public function testAFreshInstallCreatesTheGrantsTableUnderItsNewNameDirectly(): void
    {
        $db = new wpdb();
        // No legacy table on a fresh install: both existence probes miss.
        $db->varReturnQueue = [null];

        Schema::install($db);

        $this->assertStringContainsString('wp_agsafe_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
        $this->assertSame([], self::renameQueries($db), 'a fresh install has nothing to RENAME');
        $this->assertFalse($GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] ?? false);
    }

    /** @return list<string> */
    private static function renameQueries(wpdb $db): array
    {
        return array_values(array_filter(
            $db->queries,
            static fn (string $query): bool => str_starts_with($query, 'RENAME TABLE')
        ));
    }

    public function testUpgradingFromVersionThreeRenamesTheLegacyGrantsTable(): void
    {
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '3';
        $db = new wpdb();
        // Legacy table found (SHOW TABLES LIKE echoes the name back); the new
        // name isn't there yet.
        $db->varReturnQueue = ['wp_agent_safety_grants', null];

        Schema::maybeUpgrade($db);

        $this->assertSame(
            ['RENAME TABLE wp_agent_safety_grants TO wp_agsafe_grants'],
            self::renameQueries($db)
        );
        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
        $this->assertArrayNotHasKey(Schema::GRANTS_RENAME_CONFLICT_OPTION, $GLOBALS['wpas_test_options']);
    }

    public function testRunningTheUpgradeASecondTimeIsANoOp(): void
    {
        // First run: legacy present, renames it. Second run (Schema::install()
        // directly, bypassing the version short-circuit so the rename probe
        // itself is exercised again): the legacy table is gone, so there's
        // nothing left to RENAME and no error.
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '3';
        $db = new wpdb();
        $db->varReturnQueue = ['wp_agent_safety_grants', null];
        Schema::maybeUpgrade($db);

        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);

        $db->varReturnQueue = [null];
        $db->queries = [];
        Schema::install($db);

        $this->assertSame([], self::renameQueries($db), 'nothing left to RENAME once already migrated');
        $this->assertSame(Schema::VERSION, $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION]);
    }

    public function testBothGrantsTablesPresentLeavesBothAndFlagsForAnAdmin(): void
    {
        // A crashed earlier rename: both the legacy and the new table exist.
        $GLOBALS['wpas_test_options'][Schema::VERSION_OPTION] = '3';
        $db = new wpdb();
        $db->varReturnQueue = ['wp_agent_safety_grants', 'wp_agsafe_grants'];

        Schema::maybeUpgrade($db);

        $this->assertSame([], self::renameQueries($db), 'neither table is touched when both exist');
        $this->assertTrue($GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION]);
        // dbDelta still receives (and so maintains) the NEW table's name.
        $this->assertStringContainsString('wp_agsafe_grants', $GLOBALS['wpas_test_dbdelta_queries'][2]);
    }

    public function testTheConflictFlagClearsOnceTheLegacyTableIsGone(): void
    {
        $GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] = true;
        $db = new wpdb();
        $db->varReturnQueue = [null];

        Schema::install($db);

        $this->assertArrayNotHasKey(Schema::GRANTS_RENAME_CONFLICT_OPTION, $GLOBALS['wpas_test_options']);
    }

    public function testTheConflictNoticeRendersOnlyWhenTheFlagIsSet(): void
    {
        ob_start();
        Schema::renderGrantsRenameConflictNotice();
        $this->assertSame('', ob_get_clean());

        $GLOBALS['wpas_test_options'][Schema::GRANTS_RENAME_CONFLICT_OPTION] = true;

        ob_start();
        Schema::renderGrantsRenameConflictNotice();
        $notice = ob_get_clean();

        $this->assertStringContainsString('notice-warning', $notice);
        $this->assertStringContainsString('agent_safety_grants', $notice);
    }
}
