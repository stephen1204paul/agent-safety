<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Approval;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\Grant;
use Specflux\AgentSafety\Approval\GrantStatus;

/**
 * The pure usability rule every {@see \Specflux\AgentSafety\Approval\GrantStore}
 * implementation must honour. Kept in the core, clock-free, so the whole
 * fail-closed matrix (TTL, exhaustion, revocation, scope, verb, empty subject)
 * is provable without a database — the SQL guard in the wpdb-backed store is
 * atomicity, not a second opinion.
 */
final class GrantTest extends TestCase
{
    private const NOW = '2026-08-30 12:00:00';

    private function grant(
        int $remaining = 3,
        ?string $subject = 'app:key-1',
        string $expires = '2026-08-31 12:00:00',
        ?string $revoked = null,
        GrantStatus $status = GrantStatus::Active,
        string $correlation = 'senroflux:run:7',
        string $verb = 'core/post-publish',
    ): Grant {
        return new Grant(
            grantId: 'gnt_1',
            correlationId: $correlation,
            verb: $verb,
            remainingCount: $remaining,
            subject: $subject,
            grantedBy: 5,
            planStepId: 'step_2',
            createdTs: '2026-08-30 11:00:00',
            expiresTs: $expires,
            revokedTs: $revoked,
            status: $status,
        );
    }

    public function testAnActiveInScopeGrantWithRemainingCountMatches(): void
    {
        $this->assertTrue(
            $this->grant()->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-1', self::NOW)
        );
    }

    public function testAnEmptySubjectNeverMatches(): void
    {
        $grant = $this->grant();

        $this->assertFalse($grant->canReserve('senroflux:run:7', 'core/post-publish', '', self::NOW));
        $this->assertFalse($grant->canReserve('senroflux:run:7', 'core/post-publish', null, self::NOW));
    }

    public function testAGrantStoredWithoutASubjectIsNeverClaimable(): void
    {
        // The mirror of the rule above: a principal-less grant must not become
        // a grant to whoever asks first.
        foreach ([null, ''] as $stored) {
            $grant = $this->grant(subject: $stored);
            $this->assertFalse($grant->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-1', self::NOW));
        }
    }

    public function testAnEmptyCorrelationNeverMatches(): void
    {
        $grant = $this->grant(correlation: '');

        $this->assertFalse($grant->canReserve('', 'core/post-publish', 'app:key-1', self::NOW));
    }

    public function testADifferentCorrelationNeverMatches(): void
    {
        $this->assertFalse(
            $this->grant()->canReserve('senroflux:run:8', 'core/post-publish', 'app:key-1', self::NOW)
        );
    }

    public function testADifferentSubjectNeverMatches(): void
    {
        $this->assertFalse(
            $this->grant()->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-2', self::NOW)
        );
    }

    public function testADifferentVerbNeverMatches(): void
    {
        // A grant is per-verb: no globbing, no prefix matching.
        $this->assertFalse(
            $this->grant()->canReserve('senroflux:run:7', 'core/post-delete', 'app:key-1', self::NOW)
        );
    }

    public function testAnExhaustedCountNeverMatches(): void
    {
        $this->assertFalse(
            $this->grant(remaining: 0)->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-1', self::NOW)
        );
    }

    public function testALapsedTtlNeverMatchesEvenWithCountLeft(): void
    {
        $expired = $this->grant(remaining: 99, expires: '2026-08-30 11:59:59');

        $this->assertFalse($expired->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-1', self::NOW));
    }

    public function testTheTtlBoundaryIsExclusive(): void
    {
        // expires_ts == now is already dead: the wall is a hard one.
        $atWall = $this->grant(expires: self::NOW);

        $this->assertFalse($atWall->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-1', self::NOW));
    }

    public function testARevokedTimestampNeverMatchesEvenWhileStatusLooksActive(): void
    {
        // Belt and braces: a half-written revocation (timestamp set, status not
        // yet flipped) must still fail closed.
        $revoked = $this->grant(revoked: '2026-08-30 11:30:00');

        $this->assertFalse($revoked->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-1', self::NOW));
    }

    /**
     * @return array<string, array{GrantStatus}>
     */
    public static function nonActiveStatuses(): array
    {
        return [
            'exhausted' => [GrantStatus::Exhausted],
            'expired' => [GrantStatus::Expired],
            'revoked' => [GrantStatus::Revoked],
        ];
    }

    /**
     * @dataProvider nonActiveStatuses
     */
    public function testOnlyAnActiveStatusCanEverAuthorise(GrantStatus $status): void
    {
        $grant = $this->grant(status: $status);

        $this->assertFalse($grant->canReserve('senroflux:run:7', 'core/post-publish', 'app:key-1', self::NOW));
    }

    public function testIsExhaustedTracksBothTheStatusAndTheCount(): void
    {
        $this->assertFalse($this->grant()->isExhausted());
        $this->assertTrue($this->grant(remaining: 0)->isExhausted());
        $this->assertTrue($this->grant(status: GrantStatus::Exhausted)->isExhausted());
    }
}
