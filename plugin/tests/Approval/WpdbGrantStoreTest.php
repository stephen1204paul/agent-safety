<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Approval;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\GrantStatus;
use Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore;
use wpdb;

/**
 * WpdbGrantStore is wpdb-coupled by design: the single-claim guarantee lives in
 * the conditional UPDATE itself. The fake wpdb (tests/stubs/wpdb.php) executes
 * no SQL, so — exactly as in
 * {@see \Specflux\AgentSafety\Plugin\Tests\Audit\WpdbApprovalStoreTest} — these
 * tests assert on the CONSTRUCTED statement plus the branches the store decides
 * in PHP before it ever reaches the database.
 *
 * The matching RULE (TTL, exhaustion, revocation, scope, empty subject) is
 * proven database-free against the core value object in
 * {@see \Specflux\AgentSafety\Tests\Approval\GrantTest}; the store consults that
 * same rule, so what is left to prove here is that it consults it, and that the
 * SQL guard repeats it.
 */
final class WpdbGrantStoreTest extends TestCase
{
    protected function setUp(): void
    {
        remove_all_filters('agent_safety_grant_ttl');
    }

    protected function tearDown(): void
    {
        remove_all_filters('agent_safety_grant_ttl');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'grant_id' => 'gnt_1',
            'correlation_id' => 'senroflux:run:7',
            'verb' => 'core/post-publish',
            'remaining_count' => 2,
            'subject' => 'app:key-1',
            'granted_by' => 5,
            'plan_step_id' => 'step_2',
            'status' => 'active',
            'created_ts' => gmdate('Y-m-d H:i:s', time() - 60),
            'expires_ts' => gmdate('Y-m-d H:i:s', time() + 3600),
            'revoked_ts' => null,
        ], $overrides);
    }

    // --- issue ---------------------------------------------------------------

    public function testIssueWritesAnActiveGrantWithTheRequestedCount(): void
    {
        $db = new wpdb();
        $store = new WpdbGrantStore($db);

        $grantId = $store->issue('core/post-publish', 3, 'app:key-1', 'senroflux:run:7', 5, 'step_2');

        $this->assertStringStartsWith('gnt_', $grantId);
        $data = $db->lastInsert['data'];
        $this->assertSame($grantId, $data['grant_id']);
        $this->assertSame('senroflux:run:7', $data['correlation_id']);
        $this->assertSame('core/post-publish', $data['verb']);
        $this->assertSame(3, $data['remaining_count']);
        $this->assertSame('app:key-1', $data['subject']);
        $this->assertSame(5, $data['granted_by']);
        $this->assertSame('step_2', $data['plan_step_id']);
        $this->assertSame(GrantStatus::Active->value, $data['status']);
    }

    public function testIssueNeverWritesANegativeCount(): void
    {
        $db = new wpdb();

        (new WpdbGrantStore($db))->issue('core/post-publish', -5, 'app:key-1', 'senroflux:run:7', 5, null);

        // A grant that authorises nothing, not one that wraps around.
        $this->assertSame(0, $db->lastInsert['data']['remaining_count']);
    }

    public function testIssueStampsTheTwentyFourHourDefaultTtl(): void
    {
        $db = new wpdb();
        $before = time();

        (new WpdbGrantStore($db))->issue('core/post-publish', 1, 'app:key-1', 'senroflux:run:7', 5, null);

        $expires = strtotime($db->lastInsert['data']['expires_ts'] . ' UTC');
        $this->assertGreaterThanOrEqual($before + WpdbGrantStore::TTL_SECONDS, $expires);
        $this->assertLessThanOrEqual(time() + WpdbGrantStore::TTL_SECONDS, $expires);
        $this->assertSame(86400, WpdbGrantStore::TTL_SECONDS);
    }

    public function testTheTtlFilterShortensTheHardWall(): void
    {
        add_filter('agent_safety_grant_ttl', static fn (): int => 60);
        $db = new wpdb();
        $before = time();

        (new WpdbGrantStore($db))->issue('core/post-publish', 1, 'app:key-1', 'senroflux:run:7', 5, null);

        $expires = strtotime($db->lastInsert['data']['expires_ts'] . ' UTC');
        $this->assertGreaterThanOrEqual($before + 60, $expires);
        $this->assertLessThan($before + WpdbGrantStore::TTL_SECONDS, $expires);
    }

    public function testANonsensicalTtlFilterValueIsIgnored(): void
    {
        // Fail closed on the SAFE side: an unusable filtered value falls back to
        // the documented default rather than to "no expiry".
        add_filter('agent_safety_grant_ttl', static fn (): string => 'forever');
        $db = new wpdb();
        $before = time();

        (new WpdbGrantStore($db))->issue('core/post-publish', 1, 'app:key-1', 'senroflux:run:7', 5, null);

        $expires = strtotime($db->lastInsert['data']['expires_ts'] . ' UTC');
        $this->assertGreaterThanOrEqual($before + WpdbGrantStore::TTL_SECONDS, $expires);
    }

    // --- reserve -------------------------------------------------------------

    public function testReserveClaimsTheCandidateRow(): void
    {
        $db = new wpdb();
        $db->rowReturn = $this->row();
        $db->queryReturn = 1;
        $store = new WpdbGrantStore($db);

        $this->assertSame('gnt_1', $store->reserve('senroflux:run:7', 'core/post-publish', 'app:key-1'));
    }

    public function testTheClaimingUpdateRepeatsEveryFailClosedCondition(): void
    {
        $db = new wpdb();
        $db->rowReturn = $this->row();
        $db->queryReturn = 1;

        (new WpdbGrantStore($db))->reserve('senroflux:run:7', 'core/post-publish', 'app:key-1');

        $sql = end($db->queries);
        $this->assertStringContainsString("grant_id = 'gnt_1'", $sql);
        $this->assertStringContainsString("status = 'active'", $sql);
        $this->assertStringContainsString('remaining_count > 0', $sql);
        $this->assertStringContainsString('revoked_ts IS NULL', $sql);
        $this->assertStringContainsString('expires_ts > UTC_TIMESTAMP()', $sql);
        $this->assertStringContainsString('remaining_count = remaining_count - 1', $sql);
    }

    public function testTheClaimSealsTheGrantWhenTheCountReachesZero(): void
    {
        $db = new wpdb();
        $db->rowReturn = $this->row(['remaining_count' => 1]);
        $db->queryReturn = 1;

        (new WpdbGrantStore($db))->reserve('senroflux:run:7', 'core/post-publish', 'app:key-1');

        $this->assertStringContainsString(
            "CASE WHEN remaining_count - 1 <= 0 THEN 'exhausted' ELSE 'active' END",
            end($db->queries)
        );
    }

    public function testALostRaceReservesNothing(): void
    {
        // The row looked claimable at SELECT time, but a concurrent reserve got
        // there first: affected = 0, so this call must claim nothing.
        $db = new wpdb();
        $db->rowReturn = $this->row();
        $db->queryReturn = 0;

        $this->assertNull(
            (new WpdbGrantStore($db))->reserve('senroflux:run:7', 'core/post-publish', 'app:key-1')
        );
    }

    public function testAnEmptySubjectNeverEvenQueries(): void
    {
        foreach ([null, ''] as $subject) {
            $db = new wpdb();
            $db->rowReturn = $this->row();
            $db->queryReturn = 1;

            $this->assertNull((new WpdbGrantStore($db))->reserve('senroflux:run:7', 'core/post-publish', $subject));
            // Not even the lazy CREATE TABLE ran: an unauthenticated caller
            // cannot so much as probe for a grant.
            $this->assertSame([], $db->queries);
        }
    }

    public function testAnEmptyCorrelationNeverEvenQueries(): void
    {
        $db = new wpdb();
        $db->rowReturn = $this->row();

        $this->assertNull((new WpdbGrantStore($db))->reserve('', 'core/post-publish', 'app:key-1'));
        $this->assertSame([], $db->queries);
    }

    public function testNoCandidateReservesNothing(): void
    {
        $db = new wpdb();
        $db->rowReturn = null;

        $this->assertNull(
            (new WpdbGrantStore($db))->reserve('senroflux:run:7', 'core/post-publish', 'app:key-1')
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function unusableRows(): array
    {
        return [
            'exhausted count' => [['remaining_count' => 0]],
            'lapsed ttl' => [['expires_ts' => '2000-01-01 00:00:00']],
            'revoked out of band' => [['revoked_ts' => '2026-01-01 00:00:00']],
            'non-active status' => [['status' => 'revoked']],
            'stored without a subject' => [['subject' => null]],
            'different subject' => [['subject' => 'app:key-2']],
            'different correlation' => [['correlation_id' => 'senroflux:run:8']],
            'different verb' => [['verb' => 'core/post-delete']],
            'unrecognised status' => [['status' => 'totally-fine-honest']],
        ];
    }

    /**
     * A row the database handed back but the CORE rule refuses is never claimed —
     * the store must not out-trust {@see \Specflux\AgentSafety\Approval\Grant::canReserve()}.
     *
     * @dataProvider unusableRows
     * @param array<string, mixed> $overrides
     */
    public function testARowTheCoreRuleRefusesIsNeverClaimed(array $overrides): void
    {
        $db = new wpdb();
        $db->rowReturn = $this->row($overrides);
        $db->queryReturn = 1;

        $this->assertNull(
            (new WpdbGrantStore($db))->reserve('senroflux:run:7', 'core/post-publish', 'app:key-1')
        );
        foreach ($db->queries as $sql) {
            $this->assertStringNotContainsString('UPDATE', $sql);
        }
    }

    // --- release -------------------------------------------------------------

    public function testReleaseGivesTheReservationBackAndReopensAnExhaustedGrant(): void
    {
        $db = new wpdb();

        (new WpdbGrantStore($db))->release('gnt_1');

        $sql = end($db->queries);
        $this->assertStringContainsString('remaining_count = remaining_count + 1', $sql);
        $this->assertStringContainsString("status = 'active'", $sql);
        $this->assertStringContainsString("status IN ('active', 'exhausted')", $sql);
    }

    public function testReleaseNeverResurrectsARevokedOrExpiredGrant(): void
    {
        $db = new wpdb();

        (new WpdbGrantStore($db))->release('gnt_1');

        $sql = end($db->queries);
        $this->assertStringContainsString('revoked_ts IS NULL', $sql);
        $this->assertStringContainsString('expires_ts > UTC_TIMESTAMP()', $sql);
        $this->assertStringNotContainsString("'revoked'", $sql);
    }

    // --- revoke --------------------------------------------------------------

    public function testRevokeWithdrawsEveryLiveGrantInTheScope(): void
    {
        $db = new wpdb();
        $db->queryReturn = 4;
        $store = new WpdbGrantStore($db);

        $this->assertSame(4, $store->revokeAll('senroflux:run:7'));
        $sql = end($db->queries);
        $this->assertStringContainsString("status = 'revoked'", $sql);
        $this->assertStringContainsString('revoked_ts = UTC_TIMESTAMP()', $sql);
        $this->assertStringContainsString("correlation_id = 'senroflux:run:7'", $sql);
        $this->assertStringContainsString("status IN ('active', 'exhausted')", $sql);
    }

    public function testASecondRevokeRewritesNothing(): void
    {
        // Every terminal path calls revoke, so it must be a no-op the second
        // time rather than re-stamping the original revocation timestamp.
        $db = new wpdb();
        $db->queryReturn = 0;

        $this->assertSame(0, (new WpdbGrantStore($db))->revokeAll('senroflux:run:7'));
    }

    public function testRevokingAnEmptyScopeIsRefused(): void
    {
        $db = new wpdb();
        $db->queryReturn = 999;

        $this->assertSame(0, (new WpdbGrantStore($db))->revokeAll(''));
        $this->assertSame([], $db->queries);
    }

    public function testRevokeByCorrelationIsTheSameOperation(): void
    {
        $db = new wpdb();
        $db->queryReturn = 1;

        (new WpdbGrantStore($db))->revokeByCorrelation('senroflux:run:7');

        $this->assertStringContainsString("status = 'revoked'", end($db->queries));
    }

    // --- get -----------------------------------------------------------------

    public function testGetHydratesTheStoredRow(): void
    {
        $db = new wpdb();
        $db->rowReturn = $this->row();

        $grant = (new WpdbGrantStore($db))->get('gnt_1');

        $this->assertNotNull($grant);
        $this->assertSame('gnt_1', $grant->grantId);
        $this->assertSame('senroflux:run:7', $grant->correlationId);
        $this->assertSame(2, $grant->remainingCount);
        $this->assertSame(5, $grant->grantedBy);
        $this->assertSame('step_2', $grant->planStepId);
        $this->assertSame(GrantStatus::Active, $grant->status);
    }

    public function testAnUnrecognisedStatusHydratesAsRevoked(): void
    {
        // Schema drift or a hand-edited row must read as the most restrictive
        // value, never as active.
        $db = new wpdb();
        $db->rowReturn = $this->row(['status' => 'probably-fine']);

        $this->assertSame(GrantStatus::Revoked, (new WpdbGrantStore($db))->get('gnt_1')?->status);
    }

    public function testGetOnAnUnknownIdIsNull(): void
    {
        $db = new wpdb();
        $db->rowReturn = null;

        $this->assertNull((new WpdbGrantStore($db))->get('gnt_nope'));
        $this->assertNull((new WpdbGrantStore($db))->get(''));
    }

    // --- sweep ---------------------------------------------------------------

    public function testTheSweepOnlyDeletesLapsedActiveGrants(): void
    {
        $db = new wpdb();
        $db->queryReturn = 2;
        $store = new WpdbGrantStore($db);

        $this->assertSame(2, $store->deleteExpired('2026-08-30 12:00:00'));
        $sql = end($db->queries);
        $this->assertStringContainsString("status = 'active' AND expires_ts <= '2026-08-30 12:00:00'", $sql);
        // Revoked and exhausted rows ARE the record of a decision.
        $this->assertStringNotContainsString("'revoked'", $sql);
        $this->assertStringNotContainsString("'exhausted'", $sql);
    }
}
