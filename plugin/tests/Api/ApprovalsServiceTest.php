<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Api;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Plugin\Api\Approvals;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Container;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use wpdb;

/**
 * Exercises {@see Approvals} (AS-10) — the programmatic approvals API every
 * caller shares (the wp-admin page delegates here too, so "same audit as the
 * admin-post path" holds by construction; these tests pin the audit rows and
 * lifecycle actions that must come out of ANY resolution path).
 */
final class ApprovalsServiceTest extends TestCase
{
    private InMemoryAuditSink $sink;

    private wpdb $db;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_user_caps'] = [];
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_current_user_id'] = 1;
        RequestContext::reset();
        Container::reset();
        $this->sink = new InMemoryAuditSink();
        $this->db = new wpdb();
    }

    public function testApproveRequiresCapability(): void
    {
        // No manage_options cap -> denied BEFORE any store mutation.
        $service = $this->service();

        $this->assertFalse($service->approve('apr_abc', 7));

        $updates = array_filter($this->db->queries, static fn (string $q): bool => str_starts_with(trim($q), 'UPDATE'));
        $this->assertSame([], $updates, 'an unauthorized caller must never reach the store');
        $this->assertSame([], $this->resolvedActions());
    }

    public function testApproveWritesSameAuditAsAdminPost(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;
        $this->db->queryReturn = 1; // the conditional UPDATE flips exactly one pending row
        $this->db->rowReturn = $this->row();
        $service = $this->service();

        $this->assertTrue($service->approve('apr_abc', 7));

        // The reconciliation row: same shape the admin-post form wrote pre-AS-10.
        $this->assertCount(1, $this->sink->records);
        $record = $this->sink->records[0];
        $this->assertSame(AuditDecision::Approved, $record->decision);
        $this->assertSame('woocommerce/orders-refund', $record->ability);
        $this->assertSame(['id' => 'apr_abc', 'approver' => 7], $record->approval);
        $this->assertSame(['args_hash' => 'hash_123'], $record->input);
        $this->assertSame('sess_corr', $record->correlationId);
        $this->assertNull($record->tier);

        // And the resolved action fired exactly once with the decision.
        $this->assertSame([['apr_abc', 'approved', 7]], $this->resolvedActions());
    }

    public function testApproveReturningTokenMintsAShowOnceToken(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;
        $this->db->queryReturn = 1;
        $service = $this->service();

        $token = $service->approveReturningToken('apr_abc', 7);

        $this->assertNotNull($token);
        $this->assertStringStartsWith('apt_', $token);
    }

    public function testFailedStoreFlipAppendsNoAuditAndFiresNoAction(): void
    {
        // queryReturn 0 = the row wasn't pending (or was past its TTL): nothing
        // happened, so there must be no reconciliation row and no action.
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;
        $service = $this->service();

        $this->assertFalse($service->approve('apr_gone', 7));
        $this->assertSame([], $this->sink->records);
        $this->assertSame([], $this->resolvedActions());
    }

    public function testRejectWritesRejectedReconciliation(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;
        $this->db->queryReturn = 1;
        $this->db->rowReturn = $this->row();
        $service = $this->service();

        $this->assertTrue($service->reject('apr_abc', 7));

        $this->assertCount(1, $this->sink->records);
        $record = $this->sink->records[0];
        $this->assertSame(AuditDecision::Rejected, $record->decision);
        $this->assertSame(['id' => 'apr_abc', 'approver' => 7], $record->approval);
        $this->assertSame([['apr_abc', 'rejected', 7]], $this->resolvedActions());

        // A second rejection attempt (row now 'rejected', not 'pending') flips
        // nothing and appends nothing.
        $this->db->queryReturn = 0;
        $this->assertFalse($service->reject('apr_abc', 7));
        $this->assertCount(1, $this->sink->records);
    }

    public function testFindReturnsSummary(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;
        $this->db->rowReturn = $this->row();
        $service = $this->service();

        $summary = $service->find('apr_abc');

        $this->assertNotNull($summary);
        $this->assertSame('apr_abc', $summary->id);
        $this->assertSame('woocommerce/orders-refund', $summary->verb);
        $this->assertSame('pending', $summary->status);
        $this->assertSame('Refund order 42', $summary->summary);
        $this->assertSame('sess_corr', $summary->correlationId);
        $this->assertSame('2026-08-23 10:00:00', $summary->createdAtUtc);
        $this->assertSame('2026-08-23 11:00:00', $summary->pendingExpiresAtUtc);
    }

    public function testFindUnknownOrBlankIdReturnsNull(): void
    {
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;
        $this->db->rowReturn = null;
        $service = $this->service();

        $this->assertNull($service->find('apr_missing'));

        // Blank ids short-circuit before touching the store at all.
        $queriesBefore = count($this->db->queries);
        $this->assertNull($service->find(''));
        $this->assertSame($queriesBefore, count($this->db->queries));
    }

    /**
     * The bootstrap contract: the container exposes THIS service, and the
     * global locator reaches it feature-safely.
     */
    public function testContainerExposesTheServiceThroughTheGlobalLocator(): void
    {
        $this->assertTrue(function_exists('agent_safety'), 'the global locator must exist once api.php has loaded');
        $this->assertNull(agent_safety(), 'before bootstrap init the container is null');

        $service = $this->service();
        Container::init($service);

        $container = agent_safety();
        $this->assertNotNull($container);
        $this->assertSame($service, $container->approvals());
    }

    private function service(): Approvals
    {
        return new Approvals(new WpdbApprovalStore($this->db), $this->sink);
    }

    /** @return list<mixed[]> args of every agent_safety_approval_resolved firing */
    private function resolvedActions(): array
    {
        return array_map(
            static fn (array $a): array => array_slice($a, 1),
            array_values(array_filter(
                $GLOBALS['wpas_test_actions'],
                static fn (array $a): bool => $a[0] === 'agent_safety_approval_resolved',
            )),
        );
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'approval_id' => 'apr_abc',
            'verb' => 'woocommerce/orders-refund',
            'args_hash' => 'hash_123',
            'summary' => 'Refund order 42',
            'correlation_id' => 'sess_corr',
            'status' => 'pending',
            'created_ts' => '2026-08-23 10:00:00',
            'pending_expires_ts' => '2026-08-23 11:00:00',
        ];
    }
}
