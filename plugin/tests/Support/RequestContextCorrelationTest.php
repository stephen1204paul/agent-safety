<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Specflux\AgentSafety\Plugin\Support\CorrelationConflict;
use Specflux\AgentSafety\Plugin\Support\RequestContext;

/**
 * The host-settable correlation scope (AS-12). With grants enabled the
 * correlation id stops being a log tag and becomes half of a grant's match
 * key, so these tests pin the two properties that keep it safe: it is always
 * restored, and it can never be silently switched out from under a reader that
 * already pinned a different one.
 */
final class RequestContextCorrelationTest extends TestCase
{
    protected function setUp(): void
    {
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
    }

    public function testTheIdIsPinnedForTheDurationOfTheCallback(): void
    {
        $seen = RequestContext::withCorrelation(
            'senroflux:run:42',
            static fn (): string => RequestContext::correlation()
        );

        $this->assertSame('senroflux:run:42', $seen);
    }

    public function testTheCallbackReturnValueIsPassedThrough(): void
    {
        $this->assertSame(
            ['ok' => true],
            RequestContext::withCorrelation('senroflux:run:1', static fn (): array => ['ok' => true])
        );
    }

    public function testThePriorIdIsRestoredAfterwards(): void
    {
        $before = RequestContext::correlation(); // memoizes sess_…

        RequestContext::withCorrelation($before, static fn (): bool => true);

        $this->assertSame($before, RequestContext::correlation());
    }

    public function testTheAbsenceOfAPriorIdIsAlsoRestored(): void
    {
        // Nothing memoized going in: nothing may be memoized coming out, or the
        // NEXT run in this process would inherit this run's scope.
        RequestContext::withCorrelation('senroflux:run:1', static fn (): bool => true);

        $this->assertStringStartsWith('sess_', RequestContext::correlation());
    }

    public function testThePriorIdIsRestoredEvenWhenTheCallbackThrows(): void
    {
        try {
            RequestContext::withCorrelation('senroflux:run:1', static function (): void {
                throw new RuntimeException('tick blew up');
            });
            $this->fail('The callback exception should propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('tick blew up', $e->getMessage());
        }

        $this->assertStringStartsWith('sess_', RequestContext::correlation());
    }

    public function testTwoRunsInOneProcessNeverSeeEachOthersScope(): void
    {
        // The property the whole scoped design exists for: PHPUnit, WP-CLI and
        // wp-cron all tick more than one run per PHP lifetime.
        $first = RequestContext::withCorrelation(
            'senroflux:run:1',
            static fn (): string => RequestContext::correlation()
        );
        RequestContext::reset(); // the host clears between runs; see the next test for when it doesn't
        $second = RequestContext::withCorrelation(
            'senroflux:run:2',
            static fn (): string => RequestContext::correlation()
        );

        $this->assertSame('senroflux:run:1', $first);
        $this->assertSame('senroflux:run:2', $second);
        $this->assertNotSame($first, $second);
    }

    public function testADifferentAlreadyMemoizedIdThrows(): void
    {
        RequestContext::withCorrelation('senroflux:run:1', static fn (): bool => true);
        $leaked = RequestContext::correlation(); // an audit handler reads it: sess_… is now pinned

        $this->expectException(CorrelationConflict::class);

        try {
            RequestContext::withCorrelation('senroflux:run:2', static fn (): bool => true);
        } finally {
            // …and the conflicting id never took effect.
            $this->assertSame($leaked, RequestContext::correlation());
        }
    }

    public function testReenteringTheSameIdIsAllowed(): void
    {
        $nested = RequestContext::withCorrelation(
            'senroflux:run:1',
            static fn (): string => RequestContext::withCorrelation(
                'senroflux:run:1',
                static fn (): string => RequestContext::correlation()
            )
        );

        $this->assertSame('senroflux:run:1', $nested);
    }

    public function testAnEmptyIdIsRefused(): void
    {
        $this->expectException(CorrelationConflict::class);

        RequestContext::withCorrelation('', static fn (): bool => true);
    }

    public function testTheCallbackNeverRunsWhenTheIdIsRefused(): void
    {
        $ran = false;
        RequestContext::correlation();

        try {
            RequestContext::withCorrelation('senroflux:run:9', static function () use (&$ran): void {
                $ran = true;
            });
        } catch (CorrelationConflict) {
            // expected
        }

        $this->assertFalse($ran, 'A refused scope must abort the unit of work, not run it unscoped.');
    }
}
