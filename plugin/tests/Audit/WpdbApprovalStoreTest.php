<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Audit;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use wpdb;

/**
 * WpdbApprovalStore is wpdb-coupled by design (the approval lifecycle's
 * atomicity guarantees live in the SQL itself — e.g. reserve()'s conditional
 * UPDATE). The fake
 * wpdb (tests/stubs/wpdb.php) doesn't execute SQL, so these tests assert on
 * the CONSTRUCTED query string rather than on rows actually being filtered —
 * deleteExpired() has no branching logic of its own besides building this one
 * statement, so the string IS the behaviour under test.
 */
final class WpdbApprovalStoreTest extends TestCase
{
    public function testDeletesStaleUnactionedPendingAndExpiredAndLapsedApprovedRows(): void
    {
        $db = new wpdb();
        $db->queryReturn = 3;
        $store = new WpdbApprovalStore($db);

        $affected = $store->deleteExpired('2026-07-07 12:00:00');

        $this->assertSame(3, $affected);
        $sql = $this->lastDeleteQuery($db);
        $this->assertStringContainsString("status = 'pending' AND pending_expires_ts <= '2026-07-07 12:00:00'", $sql);
        $this->assertStringContainsString("status = 'expired'", $sql);
        $this->assertStringContainsString("status = 'approved' AND expires_ts <= '2026-07-07 12:00:00'", $sql);
    }

    public function testNeverTargetsInFlightRejectedOrConsumedRows(): void
    {
        $db = new wpdb();
        $store = new WpdbApprovalStore($db);

        $store->deleteExpired('2026-07-07 12:00:00');

        $sql = $this->lastDeleteQuery($db);
        $this->assertStringNotContainsString('in_flight', $sql);
        $this->assertStringNotContainsString('rejected', $sql);
        $this->assertStringNotContainsString('consumed', $sql);
    }

    public function testNeverTargetsAStillLivePendingRequest(): void
    {
        $db = new wpdb();
        $store = new WpdbApprovalStore($db);

        $store->deleteExpired('2026-07-07 12:00:00');
        $sql = $this->lastDeleteQuery($db);

        // The pending arm is conditioned on pending_expires_ts <= :now, so a
        // pending row whose window hasn't lapsed can never match it.
        $this->assertStringContainsString('pending_expires_ts <=', $sql);
        $this->assertStringNotContainsString("status = 'pending' AND pending_expires_ts >", $sql);
    }

    public function testReturnsZeroWhenTheDriverReportsANonIntResult(): void
    {
        $db = new wpdb();
        $db->queryReturn = false;
        $store = new WpdbApprovalStore($db);

        $this->assertSame(0, $store->deleteExpired('2026-07-07 12:00:00'));
    }

    private function lastDeleteQuery(wpdb $db): string
    {
        $deletes = array_values(array_filter($db->queries, static fn (string $q): bool => str_starts_with(trim($q), 'DELETE')));
        self::assertNotEmpty($deletes, 'expected deleteExpired() to issue a DELETE query');

        return (string) end($deletes);
    }
}
