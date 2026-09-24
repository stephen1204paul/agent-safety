<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\EnvironmentGuard;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use wpdb;

/**
 * AS-7 §3.4: site binding, first bind, the once-only void on a mismatch
 * (shadow emptied, grants revoked, approved-but-unclaimed approvals voided —
 * pending rows untouched), and rebind. {@see WpdbGrantStore}/
 * {@see WpdbApprovalStore} are exercised against the fake wpdb
 * (tests/stubs/wpdb.php) — no SQL executes, so assertions are on the audited
 * side effects and the canned data these stores read back.
 */
final class EnvironmentGuardTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_home_url'] = 'https://example.com';
        $GLOBALS['wpas_test_time'] = self::NOW;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        unset($GLOBALS['wpas_test_home_url']);
        $GLOBALS['wpas_test_time'] = \time();
    }

    private function guard(InMemoryAuditSink $sink, ?WpdbApprovalStore $approvals = null, ?WpdbGrantStore $grants = null): EnvironmentGuard
    {
        return new EnvironmentGuard(new ShadowMode(), new AdminChangeRecorder($sink), $approvals, $grants);
    }

    // --- first bind ------------------------------------------------------

    public function testFirstBindSetsTheOptionAndAuditsWhenUnbound(): void
    {
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink);

        $guard->firstBindIfNeeded();

        $this->assertSame('example.com', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
        $this->assertCount(1, $sink->records);
        $this->assertSame(AdminChangeRecorder::EVENT_ENVIRONMENT_BOUND, $sink->records[0]->reason);
    }

    public function testFirstBindIsANoOpOnceAlreadyBound(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'already-bound.example';
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink);

        $guard->firstBindIfNeeded();

        $this->assertSame('already-bound.example', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
        $this->assertCount(0, $sink->records);
    }

    // --- no-op when hosts agree --------------------------------------------

    public function testEnsureCurrentDoesNothingWhenTheHostMatches(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'example.com';
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink);

        $guard->ensureCurrent();

        $this->assertCount(0, $sink->records);
        $this->assertArrayNotHasKey(EnvironmentGuard::LOCK_OPTION, $GLOBALS['wpas_test_options']);
    }

    public function testHttpToHttpsIsNotAMismatch(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'example.com';
        $GLOBALS['wpas_test_home_url'] = 'http://example.com';
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink);

        $guard->ensureCurrent();

        $this->assertCount(0, $sink->records);
        $this->assertFalse($guard->isMismatched());
    }

    public function testAPathChangeIsAMismatch(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'example.com/shop';
        $GLOBALS['wpas_test_home_url'] = 'https://example.com/staging';
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink);

        $this->assertTrue($guard->isMismatched());
    }

    // --- the void, exactly once --------------------------------------------

    public function testAMismatchVoidsShadowGrantsAndApprovedUnclaimedApprovalsExactlyOnce(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $GLOBALS['wpas_test_home_url'] = 'https://new-host.example';
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = [
            'support-agent' => self::NOW + 3600,
            'owner' => self::NOW + 3600,
        ];

        $db = new wpdb();
        $db->queryReturn = 3; // grants revoked
        $db->resultsReturn = [
            ['approval_id' => 'apr_1', 'verb' => 'demo/refund', 'key_id' => 'app:1'],
            ['approval_id' => 'apr_2', 'verb' => 'demo/cancel', 'key_id' => 'app:2'],
        ];

        $approvals = new WpdbApprovalStore($db);
        $grants = new WpdbGrantStore($db);
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink, $approvals, $grants);

        $guard->ensureCurrent();

        // Shadow emptied.
        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);

        // Grants revoked: the UPDATE reached the grants table.
        $grantQueries = array_filter($db->queries, static fn (string $q): bool => str_contains($q, 'agsafe_grants') && str_contains($q, "'revoked'"));
        $this->assertNotEmpty($grantQueries);

        // Approvals voided: the UPDATE reached the approvals table with the
        // terminal status, scoped to `approved` rows only — pending rows are
        // never touched by this WHERE clause.
        $approvalQueries = array_filter(
            $db->queries,
            static fn (string $q): bool => str_contains($q, 'agsafe_approvals') && str_contains($q, "'void_environment'"),
        );
        $this->assertNotEmpty($approvalQueries);
        foreach ($approvalQueries as $q) {
            $this->assertStringContainsString("status = 'approved'", $q);
        }

        $reasons = array_map(static fn ($r) => $r->reason, $sink->records);
        $this->assertContains(AdminChangeRecorder::EVENT_SHADOW_DISABLED, $reasons);
        $this->assertContains(AdminChangeRecorder::EVENT_GRANTS_VOID_ENVIRONMENT, $reasons);
        $this->assertContains(AdminChangeRecorder::EVENT_APPROVAL_VOID_ENVIRONMENT, $reasons);
        $this->assertSame(2, count(array_filter($reasons, static fn ($r) => $r === AdminChangeRecorder::EVENT_APPROVAL_VOID_ENVIRONMENT)));
        $this->assertContains(AdminChangeRecorder::EVENT_ENVIRONMENT_MISMATCH, $reasons);
        $this->assertSame(1, count(array_filter($reasons, static fn ($r) => $r === AdminChangeRecorder::EVENT_ENVIRONMENT_MISMATCH)));

        // The lock is set, so a second detection is a no-op.
        $queryCountBefore = count($db->queries);
        $sinkCountBefore = count($sink->records);
        $guard->ensureCurrent();
        $this->assertSame($queryCountBefore, count($db->queries), 'a second detection must issue no further queries');
        $this->assertSame($sinkCountBefore, count($sink->records), 'a second detection must audit nothing further');
    }

    public function testPendingApprovalsAreNeverTouchedByTheVoid(): void
    {
        // voidUnclaimedApprovals() only ever selects `approved` rows (proven
        // directly against the query string here); a pending row simply never
        // appears in resultsReturn for a real install, so it is never voided.
        $db = new wpdb();
        $db->resultsReturn = [];
        $approvals = new WpdbApprovalStore($db);

        $voided = $approvals->voidUnclaimedApprovals();

        $this->assertSame([], $voided);
        $selectQuery = $db->queries[array_key_first($db->queries)] ?? '';
        // The very first query after activation's CREATE TABLE fallback is the
        // SELECT this method issues.
        $select = current(array_filter($db->queries, static fn (string $q): bool => str_starts_with(trim($q), 'SELECT')));
        $this->assertNotFalse($select);
        $this->assertStringContainsString("status = 'approved'", $select);
        $this->assertStringNotContainsString('pending', $select);
    }

    // --- rebind --------------------------------------------------------------

    public function testRebindBindsToTheCurrentHostClearsTheLockAndAuditsOldAndNewHost(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $GLOBALS['wpas_test_options'][EnvironmentGuard::LOCK_OPTION] = 'new-host.example';
        $GLOBALS['wpas_test_home_url'] = 'https://new-host.example';
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink);

        $guard->rebind();

        $this->assertSame('new-host.example', $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION]);
        $this->assertArrayNotHasKey(EnvironmentGuard::LOCK_OPTION, $GLOBALS['wpas_test_options']);
        $this->assertFalse($guard->isMismatched());

        $this->assertCount(1, $sink->records);
        $this->assertSame(AdminChangeRecorder::EVENT_ENVIRONMENT_REBOUND, $sink->records[0]->reason);
        $this->assertSame(
            ['from' => 'old-host.example', 'to' => 'new-host.example'],
            $sink->records[0]->input,
        );
    }

    public function testRebindRestoresNothingItOnlyClearsTheMismatch(): void
    {
        $GLOBALS['wpas_test_options'][EnvironmentGuard::OPTION] = 'old-host.example';
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = []; // already emptied by an earlier void
        $GLOBALS['wpas_test_home_url'] = 'https://new-host.example';
        $sink = new InMemoryAuditSink();
        $guard = $this->guard($sink);

        $guard->rebind();

        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION], 'rebind must not restore a voided shadow set');
    }
}
