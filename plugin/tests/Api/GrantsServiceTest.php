<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Api;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Api\Grants;
use Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore;
use Specflux\AgentSafety\Plugin\Container;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\GrantRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use wpdb;

/**
 * The programmatic pre-approval surface a host (SenroFlux) drives: what it
 * refuses, and what the audit trail says afterwards.
 */
final class GrantsServiceTest extends TestCase
{
    protected function setUp(): void
    {
        remove_all_filters('agent_safety_enable_grants');
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['app:key-1']),
        ]));
        Container::reset();
    }

    protected function tearDown(): void
    {
        remove_all_filters('agent_safety_enable_grants');
        RequestContext::reset();
        Container::reset();
    }

    private function enable(): void
    {
        add_filter('agent_safety_enable_grants', static fn (): bool => true);
    }

    /**
     * @return array{Grants, wpdb, InMemoryAuditSink}
     */
    private function service(): array
    {
        $db = new wpdb();
        $sink = new InMemoryAuditSink();

        return [new Grants(new WpdbGrantStore($db), new GrantRecorder($sink)), $db, $sink];
    }

    // --- issue ---------------------------------------------------------------

    public function testIssueRefusesWhileTheFeatureIsOff(): void
    {
        [$service, $db, $sink] = $this->service();

        $this->assertFalse($service->enabled());
        $this->assertNull($service->issue('core/post-publish', 3, 'app:key-1', 'senroflux:run:7', 5, 'step_1'));
        $this->assertNull($db->lastInsert);
        $this->assertSame([], $sink->records);
    }

    /**
     * @return array<string, array{string, int, ?string, string}>
     */
    public static function refusedArguments(): array
    {
        return [
            'no verb' => ['', 3, 'app:key-1', 'senroflux:run:7'],
            'zero count' => ['core/post-publish', 0, 'app:key-1', 'senroflux:run:7'],
            'negative count' => ['core/post-publish', -1, 'app:key-1', 'senroflux:run:7'],
            'null subject' => ['core/post-publish', 3, null, 'senroflux:run:7'],
            'empty subject' => ['core/post-publish', 3, '', 'senroflux:run:7'],
            'empty scope' => ['core/post-publish', 3, 'app:key-1', ''],
        ];
    }

    /**
     * @dataProvider refusedArguments
     */
    public function testIssueRefusesNonsenseWithoutWritingAnything(
        string $verb,
        int $count,
        ?string $subject,
        string $correlationId,
    ): void {
        $this->enable();
        [$service, $db, $sink] = $this->service();

        $this->assertNull($service->issue($verb, $count, $subject, $correlationId, 5, null));
        $this->assertNull($db->lastInsert);
        $this->assertSame([], $sink->records);
    }

    public function testIssueWritesTheGrantAndAuditsIt(): void
    {
        $this->enable();
        [$service, $db, $sink] = $this->service();
        // The audit event reads the row back, so the stub must hand one over.
        $db->rowReturn = [
            'grant_id' => 'gnt_seeded',
            'correlation_id' => 'senroflux:run:7',
            'verb' => 'core/post-publish',
            'remaining_count' => 3,
            'subject' => 'app:key-1',
            'granted_by' => 5,
            'plan_step_id' => 'step_1',
            'status' => 'active',
            'created_ts' => '2026-08-30 12:00:00',
            'expires_ts' => '2026-08-31 12:00:00',
            'revoked_ts' => null,
        ];

        $grantId = $service->issue('core/post-publish', 3, 'app:key-1', 'senroflux:run:7', 5, 'step_1');

        $this->assertNotNull($grantId);
        $this->assertSame('core/post-publish', $db->lastInsert['data']['verb']);
        $this->assertCount(1, $sink->records);
        $record = $sink->records[0]->toArray();
        $this->assertSame(GrantRecorder::EVENT_ISSUED, $record['reason']);
        $this->assertSame('grant', $record['decision']);
        $this->assertSame(GrantRecorder::PACK, $record['pack']);
        $this->assertSame('senroflux:run:7', $record['correlation_id']);
        $this->assertSame('core/post-publish', $record['ability']);
        $this->assertSame(3, $record['input']['count']);
        $this->assertSame('step_1', $record['input']['plan_step_id']);
        $this->assertSame(5, $record['approval']['approver']);
    }

    // --- revoke --------------------------------------------------------------

    public function testRevokeAllReportsAndAuditsWhatItWithdrew(): void
    {
        $this->enable();
        [$service, $db, $sink] = $this->service();
        $db->queryReturn = 3;

        $this->assertSame(3, $service->revokeAll('senroflux:run:7'));
        $this->assertCount(1, $sink->records);
        $record = $sink->records[0]->toArray();
        $this->assertSame(GrantRecorder::EVENT_REVOKED, $record['reason']);
        $this->assertSame(3, $record['input']['revoked_count']);
        $this->assertSame('senroflux:run:7', $record['correlation_id']);
    }

    public function testASecondRevokeAuditsNothing(): void
    {
        // Every terminal path calls revoke, so a repeat must be silent rather
        // than filling the trail with "revoked 0 grants".
        $this->enable();
        [$service, $db, $sink] = $this->service();
        $db->queryReturn = 0;

        $this->assertSame(0, $service->revokeAll('senroflux:run:7'));
        $this->assertSame([], $sink->records);
    }

    public function testRevokeWorksEvenWithTheFeatureSwitchedOff(): void
    {
        // Turning grants off must never strand live budget.
        [$service, $db, $sink] = $this->service();
        $db->queryReturn = 2;

        $this->assertSame(2, $service->revokeAll('senroflux:run:7'));
        $this->assertCount(1, $sink->records);
    }

    public function testRevokingAnEmptyScopeIsRefused(): void
    {
        $this->enable();
        [$service, $db, $sink] = $this->service();
        $db->queryReturn = 999;

        $this->assertSame(0, $service->revokeAll(''));
        $this->assertSame([], $db->queries);
        $this->assertSame([], $sink->records);
    }

    // --- container ------------------------------------------------------------

    public function testTheContainerExposesTheServiceAndDefaultsToNull(): void
    {
        [$service] = $this->service();

        Container::init(null, $service);
        $this->assertSame($service, agent_safety()?->grants());

        Container::init(null);
        $this->assertNull(agent_safety()?->grants());
    }
}
