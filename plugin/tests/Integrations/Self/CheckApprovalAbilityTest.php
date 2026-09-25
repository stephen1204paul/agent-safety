<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Self;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Integrations\Self\CheckApprovalAbility;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use wpdb;

/**
 * AS-8 (§3.5): the actual poll logic the Verdict pipeline's exemption
 * ({@see \Specflux\AgentSafety\Plugin\Tests\Verdict\VerdictPipelineCheckApprovalTest})
 * deliberately skips — scoping, the public status/next_action table (§3.11),
 * the forbidden-field allow-list, the paused prefix, the ability's own rate
 * limit, and the `approval.probe` audit rule.
 */
final class CheckApprovalAbilityTest extends TestCase
{
    private const PRINCIPAL = 'wc:key_7';

    private wpdb $db;

    private InMemoryAuditSink $sink;

    protected function setUp(): void
    {
        $this->db = new wpdb();
        $this->sink = new InMemoryAuditSink();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = 1_800_000_000;
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: [self::PRINCIPAL]),
        ]));
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    /** @param array<string, mixed> $overrides */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'approval_id' => 'apr_1',
            'verb' => 'woocommerce/orders-refund',
            'status' => 'pending',
            'summary' => 'woocommerce/orders-refund { id=9 }',
            'key_id' => self::PRINCIPAL,
            'created_ts' => '2026-01-01 00:00:00',
            'pending_expires_ts' => '2999-01-01 00:00:00',
            'grant_id' => null,
        ], $overrides);
    }

    private function ability(?PauseSwitch $pause = null): CheckApprovalAbility
    {
        return new CheckApprovalAbility(new WpdbApprovalStore($this->db), $this->sink, $pause);
    }

    private function pause(bool $paused): PauseSwitch
    {
        $changes = new AdminChangeRecorder($this->sink);
        if ($paused) {
            $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => 1, 'by' => 1, 'reason' => 'x'];
        }

        return new PauseSwitch($changes);
    }

    // --- §3.5 item 4 / §3.11 status table ------------------------------------

    public function testPendingStatus(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'pending']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('pending', $body['status']);
        $this->assertSame(
            'Waiting for a human. Check again in 30 seconds or more, and stop at pending_expires_at.',
            $body['next_action']
        );
        $this->assertSame('human', $body['resolved_via']);
        $this->assertArrayNotHasKey('reason', $body);
    }

    public function testApprovedStatus(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'approved']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('approved', $body['status']);
        $this->assertSame('Retry the original call now, with exactly the same arguments.', $body['next_action']);
    }

    public function testRejectedStatus(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'rejected']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('rejected', $body['status']);
        $this->assertSame('A human rejected this request. Don\'t retry it; tell the user.', $body['next_action']);
    }

    public function testExpiredStatus(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'expired']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('expired', $body['status']);
        $this->assertSame('Nobody reviewed this request in time. Ask the user before trying again.', $body['next_action']);
    }

    public function testAPendingRowPastItsOwnTtlReadsAsExpired(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'pending', 'pending_expires_ts' => '2000-01-01 00:00:00']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('expired', $body['status']);
    }

    public function testInFlightMapsToUsed(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'in_flight']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('used', $body['status']);
        $this->assertSame('This approval has already been used. Another call needs a new approval.', $body['next_action']);
    }

    public function testConsumedMapsToUsed(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'consumed']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('used', $body['status']);
    }

    public function testStaleMapsToSupersededTargetChanged(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'stale']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('superseded', $body['status']);
        $this->assertSame('target_changed', $body['reason']);
        $this->assertSame('The target changed after the request. Retrying the call files a fresh request.', $body['next_action']);
    }

    public function testVoidEnvironmentMapsToSupersededSiteMoved(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'void_environment']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('superseded', $body['status']);
        $this->assertSame('site_moved', $body['reason']);
        $this->assertSame('The site\'s address changed. Retrying the call files a fresh request.', $body['next_action']);
    }

    public function testResolvedViaGrant(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'approved', 'grant_id' => 'grt_1']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame('grant', $body['resolved_via']);
    }

    // --- forbidden fields / allow-list ---------------------------------------

    public function testOnlyTheAllowListedFieldsAreReturnedForAnOrdinaryRow(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'pending']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame(
            ['approval_id', 'verb', 'summary', 'created_at', 'pending_expires_at', 'status', 'next_action', 'resolved_via'],
            array_keys($body)
        );
    }

    public function testOnlyTheAllowListedFieldsAreReturnedForASupersededRow(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'stale']);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame(
            ['approval_id', 'verb', 'summary', 'created_at', 'pending_expires_at', 'status', 'next_action', 'resolved_via', 'reason'],
            array_keys($body)
        );
        $this->assertArrayNotHasKey('approver', $body);
        $this->assertArrayNotHasKey('args_hash', $body);
        $this->assertArrayNotHasKey('correlation_id', $body);
        $this->assertArrayNotHasKey('grant_id', $body);
        $this->assertArrayNotHasKey('token', $body);
        $this->assertArrayNotHasKey('token_hash', $body);
    }

    // --- paused -----------------------------------------------------------

    public function testPausedAddsFlagAndPrefixesNextAction(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'pending']);
        $body = $this->ability($this->pause(true))->execute(['approval_id' => 'apr_1']);

        $this->assertTrue($body['paused']);
        $this->assertSame(
            'Agent Safety is paused on this site, so retrying won\'t work until an administrator resumes it. '
            . 'Waiting for a human. Check again in 30 seconds or more, and stop at pending_expires_at.',
            $body['next_action']
        );
    }

    public function testNotPausedOmitsThePausedKey(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'pending']);
        $body = $this->ability($this->pause(false))->execute(['approval_id' => 'apr_1']);

        $this->assertArrayNotHasKey('paused', $body);
    }

    // --- scoping: mismatch and unknown id are byte-identical -----------------

    public function testUnknownIdIsNotFound(): void
    {
        $this->db->rowReturn = null;
        $body = $this->ability()->execute(['approval_id' => 'apr_ghost']);

        $this->assertSame(['status' => 'not_found'], $body);
    }

    public function testPrincipalMismatchAndUnknownIdReturnByteIdenticalBodies(): void
    {
        $this->db->rowReturn = $this->row(['key_id' => 'wc:someone-else']);
        $mismatch = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->db->rowReturn = null;
        $unknown = $this->ability()->execute(['approval_id' => 'apr_ghost']);

        $this->assertSame($unknown, $mismatch);
        $this->assertSame(json_encode($unknown), json_encode($mismatch));
    }

    public function testNoPrincipalIsNotFoundEvenAgainstANullKeyIdRow(): void
    {
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([]));

        $this->db->rowReturn = $this->row(['key_id' => null]);
        $body = $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertSame(['status' => 'not_found'], $body);
    }

    public function testNoPrincipalIsNotFoundForAnUnknownIdToo(): void
    {
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([]));

        $this->db->rowReturn = null;
        $body = $this->ability()->execute(['approval_id' => 'apr_ghost']);

        $this->assertSame(['status' => 'not_found'], $body);
    }

    // --- audit: mismatch and rate-limit only ---------------------------------

    public function testSuccessfulPollWritesNoAuditRow(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'pending']);
        $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertCount(0, $this->sink->records);
    }

    public function testUnknownIdWritesNoAuditRow(): void
    {
        $this->db->rowReturn = null;
        $this->ability()->execute(['approval_id' => 'apr_ghost']);

        $this->assertCount(0, $this->sink->records);
    }

    public function testPrincipalMismatchIsAuditedAsApprovalProbe(): void
    {
        $this->db->rowReturn = $this->row(['key_id' => 'wc:someone-else']);
        $this->ability()->execute(['approval_id' => 'apr_1']);

        $this->assertCount(1, $this->sink->records);
        $record = $this->sink->records[0]->toArray();
        $this->assertSame('approval.probe', $record['reason']);
        $this->assertSame('agent-safety/check-approval', $record['ability']);
    }

    public function testTheEleventhCallInAMinuteIsRateLimitedWithRetryAfterAndAudited(): void
    {
        $this->db->rowReturn = $this->row(['status' => 'pending']);
        $ability = $this->ability();

        for ($i = 0; $i < 10; $i++) {
            $body = $ability->execute(['approval_id' => 'apr_1']);
            $this->assertSame('pending', $body['status']);
        }

        $eleventh = $ability->execute(['approval_id' => 'apr_1']);

        $this->assertSame('rate_limited', $eleventh['status']);
        $this->assertSame(60 - (1_800_000_000 % 60), $eleventh['retry_after']);
        $this->assertCount(1, $this->sink->records);
        $this->assertSame('approval.probe', $this->sink->records[0]->toArray()['reason']);
    }
}
