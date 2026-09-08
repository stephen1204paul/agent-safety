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
 * refuses, who it lets grant, and what the audit trail says afterwards.
 */
final class GrantsServiceTest extends TestCase
{
    private const VERB = 'core/post-publish';
    private const SUBJECT = 'app:key-1';
    private const SCOPE = 'senroflux:run:7';

    protected function setUp(): void
    {
        remove_all_filters('agent_safety_enable_grants');
        remove_all_filters('agent_safety_can_grant');
        remove_all_filters('agent_safety_grant_max_count');
        $GLOBALS['wpas_test_user_caps'] = [];
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        RequestContext::reset();
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['app:key-1']),
        ]));
        Container::reset();
    }

    protected function tearDown(): void
    {
        remove_all_filters('agent_safety_enable_grants');
        remove_all_filters('agent_safety_can_grant');
        remove_all_filters('agent_safety_grant_max_count');
        $GLOBALS['wpas_test_user_caps'] = [];
        $GLOBALS['wpas_test_user_caps_by_id'] = [];
        RequestContext::reset();
        Container::reset();
    }

    private function enable(): void
    {
        add_filter('agent_safety_enable_grants', static fn (): bool => true);
    }

    /** The grantor holds manage_options, so the default authorisation passes. */
    private function adminGrantor(int $userId = 5): void
    {
        $GLOBALS['wpas_test_user_caps_by_id'][$userId]['manage_options'] = true;
    }

    /**
     * The row the store hands back after an insert — the issued audit event
     * reads it, so the stub must have one to give.
     *
     * @return array<string, mixed>
     */
    private function seededRow(int $count = 3): array
    {
        return [
            'grant_id' => 'gnt_seeded',
            'correlation_id' => self::SCOPE,
            'verb' => self::VERB,
            'remaining_count' => $count,
            'subject' => self::SUBJECT,
            'granted_by' => 5,
            'plan_step_id' => 'step_1',
            'status' => 'active',
            'created_ts' => '2026-08-30 12:00:00',
            'expires_ts' => '2026-08-31 12:00:00',
            'revoked_ts' => null,
        ];
    }

    /** Exactly one audit row, and it is the refusal with this reason. */
    private function assertRefused(InMemoryAuditSink $sink, string $reason, int $count, ?int $grantedBy): void
    {
        $this->assertCount(1, $sink->records);
        $record = $sink->records[0]->toArray();
        $this->assertSame(GrantRecorder::EVENT_REFUSED, $record['reason']);
        $this->assertSame('grant', $record['decision']);
        $this->assertSame(GrantRecorder::PACK, $record['pack']);
        $this->assertSame(self::SCOPE, $record['correlation_id']);
        $this->assertSame(self::VERB, $record['ability']);
        $this->assertSame($reason, $record['input']['reason']);
        $this->assertSame($count, $record['input']['count']);
        $this->assertSame($grantedBy, $record['input']['granted_by']);
        $this->assertNull($record['approval']);
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
    public function testIssueRefusesNonsenseWithoutWritingAnythingAndAuditsIt(
        string $verb,
        int $count,
        ?string $subject,
        string $correlationId,
    ): void {
        $this->enable();
        $this->adminGrantor();
        [$service, $db, $sink] = $this->service();

        $this->assertNull($service->issue($verb, $count, $subject, $correlationId, 5, null));
        $this->assertNull($db->lastInsert);
        $this->assertCount(1, $sink->records);
        $record = $sink->records[0]->toArray();
        $this->assertSame(GrantRecorder::EVENT_REFUSED, $record['reason']);
        $this->assertSame(Grants::REFUSED_INVALID_REQUEST, $record['input']['reason']);
        $this->assertSame($count, $record['input']['count']);
        $this->assertSame(5, $record['input']['granted_by']);
    }

    public function testIssueWritesTheGrantAndAuditsIt(): void
    {
        $this->enable();
        $this->adminGrantor();
        [$service, $db, $sink] = $this->service();
        $db->rowReturn = $this->seededRow();

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

    // --- who may grant -------------------------------------------------------

    /**
     * @return array<string, array{?int}>
     */
    public static function missingGrantors(): array
    {
        return ['null' => [null], 'zero' => [0], 'negative' => [-1]];
    }

    /**
     * @dataProvider missingGrantors
     */
    public function testIssueRefusesAndAuditsAGrantWithNobodyOnIt(?int $grantedBy): void
    {
        // A grant nobody signed is a grant nobody decided: a cron or WP-CLI path
        // must carry the accepting human's id or get nothing — and not even a
        // vouching filter can stand in for the missing name.
        $this->enable();
        add_filter('agent_safety_can_grant', static fn (): bool => true);
        [$service, $db, $sink] = $this->service();

        $this->assertNull($service->issue(self::VERB, 3, self::SUBJECT, self::SCOPE, $grantedBy, null));
        $this->assertNull($db->lastInsert);
        $this->assertSame([], $db->queries);
        $this->assertRefused($sink, Grants::REFUSED_NO_GRANTOR, 3, $grantedBy);
    }

    public function testIssueRefusesAGrantorWithoutManageOptions(): void
    {
        // The CURRENT user being an administrator proves nothing about the
        // grantor: the named user is the one checked.
        $this->enable();
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;
        [$service, $db, $sink] = $this->service();

        $this->assertNull($service->issue(self::VERB, 3, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertNull($db->lastInsert);
        $this->assertSame([], $db->queries);
        $this->assertRefused($sink, Grants::REFUSED_NOT_AUTHORIZED, 3, 5);
    }

    public function testTheCanGrantFilterMayVouchForANonAdmin(): void
    {
        $this->enable();
        $seen = [];
        add_filter(
            'agent_safety_can_grant',
            static function (bool $default, int $by, string $verb, int $count, string $scope) use (&$seen): bool {
                $seen = [$default, $by, $verb, $count, $scope];

                return true;
            },
            10,
            5,
        );
        [$service, $db, $sink] = $this->service();
        $db->rowReturn = $this->seededRow();

        $this->assertNotNull($service->issue(self::VERB, 3, self::SUBJECT, self::SCOPE, 5, 'step_1'));
        $this->assertSame([false, 5, self::VERB, 3, self::SCOPE], $seen);
        $this->assertSame(5, $db->lastInsert['data']['granted_by']);
        $this->assertCount(1, $sink->records);
        $this->assertSame(GrantRecorder::EVENT_ISSUED, $sink->records[0]->toArray()['reason']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notLiterallyTrue(): array
    {
        return [
            'yes' => ['yes'],
            'one' => [1],
            'the word true' => ['true'],
            'false' => [false],
            'null' => [null],
        ];
    }

    /**
     * @dataProvider notLiterallyTrue
     */
    public function testTheCanGrantFilterMustReturnLiteralTrue(mixed $filtered): void
    {
        // Even an administrator is refused once the filter answers with anything
        // but `true`: a host that takes over the gate has to be exact, and a
        // filter that narrows (false) is honoured as written.
        $this->enable();
        $this->adminGrantor();
        add_filter('agent_safety_can_grant', static fn (): mixed => $filtered);
        [$service, $db, $sink] = $this->service();

        $this->assertNull($service->issue(self::VERB, 3, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertNull($db->lastInsert);
        $this->assertRefused($sink, Grants::REFUSED_NOT_AUTHORIZED, 3, 5);
    }

    // --- how much one grant may carry ----------------------------------------

    public function testACountAtTheCeilingIssuesAndOneAboveItIsRefused(): void
    {
        $this->enable();
        $this->adminGrantor();
        $this->assertSame(50, Grants::MAX_COUNT);

        [$service, $db] = $this->service();
        $db->rowReturn = $this->seededRow(50);
        $this->assertNotNull($service->issue(self::VERB, 50, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertSame(50, $db->lastInsert['data']['remaining_count']);

        // Refused, not clamped: the trail must show what the host asked for.
        [$service, $db, $sink] = $this->service();
        $this->assertNull($service->issue(self::VERB, 51, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertNull($db->lastInsert);
        $this->assertSame([], $db->queries);
        $this->assertRefused($sink, Grants::REFUSED_COUNT_ABOVE_MAX, 51, 5);
    }

    public function testTheMaxCountFilterCanLowerTheCeiling(): void
    {
        $this->enable();
        $this->adminGrantor();
        add_filter('agent_safety_grant_max_count', static fn (): int => 2);

        [$service, $db, $sink] = $this->service();
        $this->assertNull($service->issue(self::VERB, 3, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertNull($db->lastInsert);
        $this->assertRefused($sink, Grants::REFUSED_COUNT_ABOVE_MAX, 3, 5);

        [$service, $db] = $this->service();
        $db->rowReturn = $this->seededRow(2);
        $this->assertNotNull($service->issue(self::VERB, 2, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertSame(2, $db->lastInsert['data']['remaining_count']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonsensicalCeilings(): array
    {
        return [
            'string' => ['lots'],
            'zero' => [0],
            'negative' => [-1],
            'float' => [75.0],
            'bool' => [true],
        ];
    }

    /**
     * @dataProvider nonsensicalCeilings
     */
    public function testANonsensicalMaxCountFilterValueFallsBackToTheDefault(mixed $filtered): void
    {
        $this->enable();
        $this->adminGrantor();
        add_filter('agent_safety_grant_max_count', static fn (): mixed => $filtered);
        $max = Grants::MAX_COUNT;

        [$service, $db] = $this->service();
        $db->rowReturn = $this->seededRow($max);
        $this->assertNotNull($service->issue(self::VERB, $max, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertSame($max, $db->lastInsert['data']['remaining_count']);

        [$service, $db, $sink] = $this->service();
        $this->assertNull($service->issue(self::VERB, $max + 1, self::SUBJECT, self::SCOPE, 5, null));
        $this->assertNull($db->lastInsert);
        $this->assertRefused($sink, Grants::REFUSED_COUNT_ABOVE_MAX, $max + 1, 5);
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
