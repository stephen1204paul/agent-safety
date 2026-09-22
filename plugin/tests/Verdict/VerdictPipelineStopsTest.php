<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\ApprovalNotifier;
use Specflux\AgentSafety\Plugin\Support\ArgumentCapGate;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Support\Tripwires;
use Specflux\AgentSafety\Plugin\Support\WindowCounter;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use Specflux\AgentSafety\Plugin\Verdict\VerdictMode;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * The stops, driven through {@see VerdictPipeline::judge()} the way
 * {@see VerdictPipelineTest} drives the caps: the emergency stop
 * ({@see PauseSwitch}) ahead of the approval claim and outside shadow mode,
 * then the denial lockout and the repeat-call guard ({@see Tripwires}).
 *
 * A fresh pipeline per call stands for a fresh request — its per-request
 * memos start empty while the transients and the audit sink persist, exactly
 * as across two HTTP requests — and re-entrancy within one request is proven
 * by judging repeatedly on ONE instance.
 */
final class VerdictPipelineStopsTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const VERBS = [
        'demo/read' => Tier::Reversible,
        'demo/write' => Tier::SideEffecting,
        'demo/delete' => Tier::Irreversible,
    ];

    private InMemoryAuditSink $sink;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(Tripwires::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        remove_all_filters(PauseSwitch::FILTER);
        remove_all_filters(Tripwires::FILTER);
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = \time();
    }

    /** One request: fresh memos over the shared sink, transients and (optional) approval store. */
    private function pipeline(?FakeApprovalStore $approvals = null): VerdictPipeline
    {
        $catalog = new VerbCatalog();
        $catalog->register(self::VERBS);

        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));

        $changes = new AdminChangeRecorder($this->sink);

        return new VerdictPipeline(
            new Gate(new TierClassifier($catalog)),
            new DecisionRecorder($this->sink, $approvals),
            $approvals,
            new RateLimitGate(),
            new ArgumentCapGate(),
            new ShadowMode(),
            null,
            new PauseSwitch($changes),
            new Tripwires(new WindowCounter(), $changes, new ApprovalNotifier()),
        );
    }

    private function owner(): Pack
    {
        return new Pack(name: 'owner', allow: ['*']);
    }

    /** Reads only: every write is `not_in_pack`, the denial the lockout counts. */
    private function reader(): Pack
    {
        return new Pack(name: 'reader', allow: ['demo/read']);
    }

    private function approvalGated(): Pack
    {
        return new Pack(name: 'support', allow: ['demo/*'], approvalByClass: ['tier2' => true]);
    }

    /** $n distinct denied calls, each its own request. */
    private function deny(int $n, Pack $pack, int $from = 0): void
    {
        for ($i = $from; $i < $from + $n; $i++) {
            $verdict = $this->pipeline()->judge('demo/write', ['call' => $i], $pack, Hints::none(), VerdictMode::Claim);
            $this->assertFalse($verdict->proceeds(), "call $i");
        }
    }

    /** @return list<string> */
    private function reasons(): array
    {
        return array_map(static fn ($record): string => (string) $record->toArray()['reason'], $this->sink->records);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $reason): array
    {
        return array_values(array_filter(
            array_map(static fn ($record): array => $record->toArray(), $this->sink->records),
            static fn (array $row): bool => $row['reason'] === $reason,
        ));
    }

    // --- Emergency stop ------------------------------------------------------

    public function testAPausedSiteDeniesEveryTierInBothModes(): void
    {
        $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => self::NOW, 'by' => 7, 'reason' => 'runaway'];

        foreach ([VerdictMode::Peek, VerdictMode::Claim] as $mode) {
            foreach (self::VERBS as $verb => $tier) {
                $verdict = $this->pipeline()->judge($verb, ['n' => 1], $this->owner(), Hints::none(), $mode);

                $this->assertFalse($verdict->proceeds(), "$verb in {$mode->name}");
                $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
                $this->assertSame(PauseSwitch::REASON, $verdict->decision->reason);
                $this->assertSame($tier, $verdict->decision->tier, 'the tier the call would have had survives');
                $this->assertNotNull($verdict->error());
            }
        }

        $this->assertSame(array_fill(0, 6, PauseSwitch::REASON), $this->reasons(), 'every refusal is audited');
        $this->assertSame('denied', $this->sink->records[0]->toArray()['decision']);
    }

    public function testAPausedSiteDoesNotClaimAnApprovalAHumanAlreadyGranted(): void
    {
        $approvals = new FakeApprovalStore();
        $args = ['id' => 9];
        $id = $approvals->seedApproved('demo/delete', ApprovalBinding::hash('demo/delete', $args), 'test:token');
        $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => self::NOW, 'by' => 7, 'reason' => 'runaway'];

        $verdict = $this->pipeline($approvals)
            ->judge('demo/delete', $args, $this->approvalGated(), Hints::none(), VerdictMode::Claim);

        $this->assertSame(PauseSwitch::REASON, $verdict->decision->reason);
        $this->assertFalse($verdict->claimed);
        $this->assertNull($verdict->reservedApprovalId);
        $this->assertSame('approved', $approvals->rows[$id]['status'], 'the grant is kept for after the pause');
    }

    public function testShadowModeDoesNotLoosenThePause(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['observed' => self::NOW + 3600];
        $GLOBALS['wpas_test_options'][PauseSwitch::OPTION] = ['since' => self::NOW, 'by' => 7, 'reason' => 'runaway'];
        $observed = new Pack(name: 'observed', allow: ['*']);

        $verdict = $this->pipeline()->judge('demo/read', [], $observed, Hints::none(), VerdictMode::Claim);

        $this->assertFalse($verdict->shadowed);
        $this->assertFalse($verdict->proceeds());
        $this->assertNotNull($verdict->error());
        $this->assertFalse($this->sink->records[0]->toArray()['dry_run']);
    }

    public function testTheFilterForcesAPauseOnlyWhenItReturnsLiteralTrue(): void
    {
        add_filter(PauseSwitch::FILTER, static fn (): bool => true);
        $forced = $this->pipeline()->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Claim);
        $this->assertSame(PauseSwitch::REASON, $forced->decision->reason);

        foreach (['yes', 1, 'true'] as $truthy) {
            remove_all_filters(PauseSwitch::FILTER);
            add_filter(PauseSwitch::FILTER, static fn () => $truthy);

            $verdict = $this->pipeline()->judge('demo/read', [], $this->owner(), Hints::none(), VerdictMode::Claim);
            $this->assertTrue($verdict->proceeds(), var_export($truthy, true));
        }
    }

    public function testResumingLetsCallsThroughAgain(): void
    {
        $switch = new PauseSwitch(new AdminChangeRecorder($this->sink));

        $switch->pause(7, 'runaway');
        $this->assertFalse($this->pipeline()->judge('demo/write', [], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());

        $switch->resume(7);
        $this->assertTrue($this->pipeline()->judge('demo/write', [], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());

        $this->assertSame(
            [AdminChangeRecorder::EVENT_PAUSE_ENABLED, PauseSwitch::REASON, AdminChangeRecorder::EVENT_PAUSE_DISABLED],
            $this->reasons(),
        );
    }

    // --- Denial lockout ------------------------------------------------------

    public function testNineteenDenialsDoNothingAndTheTwentiethLocksTheTokenOut(): void
    {
        $this->deny(19, $this->reader());
        $this->assertTrue($this->pipeline()->judge('demo/read', [], $this->reader(), Hints::none(), VerdictMode::Claim)->proceeds());
        $this->assertSame([], $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED));
        $this->assertSame([], $GLOBALS['wpas_test_actions']);

        $this->deny(1, $this->reader(), from: 19);

        $locked = $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED);
        $this->assertCount(1, $locked);
        $this->assertSame('admin', $locked[0]['decision']);
        $this->assertSame(Tripwires::LOCKOUT, $locked[0]['ability']);
        $this->assertSame(['token' => 'test:token', 'denials' => 20, 'lockout_seconds' => 600], $locked[0]['input']);
        $this->assertCount(1, $GLOBALS['wpas_test_actions']);
        $this->assertSame([Tripwires::ACTION, 'test:token', Tripwires::LOCKOUT], array_slice($GLOBALS['wpas_test_actions'][0], 0, 3));
        $this->assertCount(20, $this->rows('not_in_pack'), 'the twentieth keeps its own reason; it is what tripped');

        $verdict = $this->pipeline()->judge('demo/read', [], $this->reader(), Hints::none(), VerdictMode::Claim);
        $this->assertFalse($verdict->proceeds(), 'a call that would have been allowed');
        $this->assertSame(Outcome::Deny, $verdict->decision->outcome);
        $this->assertSame(Tripwires::LOCKOUT, $verdict->decision->reason);
        $this->assertSame(Tier::Reversible, $verdict->decision->tier);
    }

    public function testLockoutDenialsNeitherFeedTheCounterNorRetrip(): void
    {
        $this->deny(20, $this->reader());

        for ($i = 0; $i < 30; $i++) {
            $verdict = $this->pipeline()->judge('demo/write', ['locked' => $i], $this->reader(), Hints::none(), VerdictMode::Claim);
            $this->assertSame(Tripwires::LOCKOUT, $verdict->decision->reason);
        }

        $this->assertCount(1, $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED));
        $this->assertCount(1, $GLOBALS['wpas_test_actions']);
        $this->assertCount(30, $this->rows(Tripwires::LOCKOUT), 'each locked-out call is still audited');
    }

    public function testTheLockoutLapsesAfterLockoutSeconds(): void
    {
        $this->deny(20, $this->reader());

        $GLOBALS['wpas_test_time'] = self::NOW + 599;
        $still = $this->pipeline()->judge('demo/read', [], $this->reader(), Hints::none(), VerdictMode::Claim);
        $this->assertSame(Tripwires::LOCKOUT, $still->decision->reason);

        $GLOBALS['wpas_test_time'] = self::NOW + 600;
        $this->assertTrue($this->pipeline()->judge('demo/read', [], $this->reader(), Hints::none(), VerdictMode::Claim)->proceeds());

        // The minute that tripped it is gone: one fresh denial is just a denial.
        $this->deny(1, $this->reader(), from: 99);
        $this->assertTrue($this->pipeline()->judge('demo/read', [], $this->reader(), Hints::none(), VerdictMode::Claim)->proceeds());
        $this->assertCount(1, $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED));
    }

    public function testApprovalParksAndCapDenialsDoNotCountTowardsTheLockout(): void
    {
        // Only what the core gate itself refuses counts: a park is a human's
        // decision pending, and a tripped cap is a budget, not a refusal.
        $pack = new Pack(name: 'gated', allow: ['*'], approvalByClass: ['tier2' => true], limits: ['calls_per_minute' => 1]);

        for ($i = 0; $i < 25; $i++) {
            $verdict = $this->pipeline()->judge('demo/delete', ['call' => $i], $pack, Hints::none(), VerdictMode::Peek);
            $this->assertSame(Outcome::ApprovalRequired, $verdict->decision->outcome);
        }
        $this->assertTrue($this->pipeline()->judge('demo/read', ['slot' => 1], $pack, Hints::none(), VerdictMode::Peek)->proceeds());
        for ($i = 0; $i < 25; $i++) {
            $verdict = $this->pipeline()->judge('demo/read', ['call' => $i], $pack, Hints::none(), VerdictMode::Peek);
            $this->assertSame('rate_limited_calls_per_minute', $verdict->decision->reason);
        }

        $this->assertSame([], $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED));
        $this->assertFalse((new Tripwires())->isLockedOut('test:token'));
    }

    public function testAShadowedPackStillTripsTheLockoutButRunsLockedCallsAsDryRuns(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['observed' => self::NOW + 3600];
        $observed = new Pack(name: 'observed', allow: ['demo/read']);

        for ($i = 0; $i < 20; $i++) {
            $verdict = $this->pipeline()->judge('demo/write', ['call' => $i], $observed, Hints::none(), VerdictMode::Claim);
            $this->assertTrue($verdict->shadowed, 'observation lets the would-be denial run');
        }
        $this->assertCount(1, $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED));

        $locked = $this->pipeline()->judge('demo/read', [], $observed, Hints::none(), VerdictMode::Claim);
        $this->assertSame(Tripwires::LOCKOUT, $locked->decision->reason);
        $this->assertTrue($locked->shadowed, 'a shadowed pack is being watched on purpose: recorded, not enforced');
        $this->assertTrue($locked->proceeds());
    }

    public function testTheSameDeniedCallRecheckedWithinOneRequestCountsOnce(): void
    {
        add_filter(Tripwires::FILTER, static fn (array $limits): array => ['denials_per_minute' => 2] + $limits);
        $request = $this->pipeline();

        for ($i = 0; $i < 11; $i++) {
            $verdict = $request->judge('demo/write', ['id' => 7], $this->reader(), Hints::none(), VerdictMode::Claim);
            $this->assertSame('not_in_pack', $verdict->decision->reason);
        }
        $this->assertSame([], $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED), 'eleven re-checks are one denial');

        $this->deny(1, $this->reader(), from: 50);
        $this->assertCount(1, $this->rows(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED));
    }

    public function testALockoutEmailsTheConfiguredRecipient(): void
    {
        $GLOBALS['wpas_test_options'][ApprovalNotifier::EMAIL_OPTION] = 'security@example.test';

        $this->deny(20, $this->reader());

        $this->assertCount(1, $GLOBALS['wpas_test_mail']);
        $this->assertSame('security@example.test', $GLOBALS['wpas_test_mail'][0]['to']);
        $this->assertStringContainsString('test:token', $GLOBALS['wpas_test_mail'][0]['subject']);
    }

    // --- Repeat-call guard ---------------------------------------------------

    public function testFiveIdenticalWritesPassAndTheSixthIsRefused(): void
    {
        $args = ['id' => 4, 'status' => 'shipped'];

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->pipeline()->judge('demo/write', $args, $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds(), "call $i");
        }

        $sixth = $this->pipeline()->judge('demo/write', $args, $this->owner(), Hints::none(), VerdictMode::Claim);
        $this->assertFalse($sixth->proceeds());
        $this->assertSame(Outcome::Deny, $sixth->decision->outcome);
        $this->assertSame(Tripwires::REPEAT, $sixth->decision->reason);
        $this->assertSame(Tier::SideEffecting, $sixth->decision->tier);
        $this->assertSame([Tripwires::REPEAT], $this->reasons(), 'the refusal is audited like any denial');
    }

    public function testADifferentArgumentValueIsADifferentCall(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pipeline()->judge('demo/write', ['id' => 4], $this->owner(), Hints::none(), VerdictMode::Claim);
        }

        $this->assertTrue($this->pipeline()->judge('demo/write', ['id' => 5], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());
        $this->assertFalse($this->pipeline()->judge('demo/write', ['id' => 4], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());
    }

    public function testTheApprovalTokenAndKeyOrderDoNotMakeANewCall(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->pipeline()->judge('demo/write', ['id' => 4, 'status' => 'shipped'], $this->owner(), Hints::none(), VerdictMode::Claim);
        }

        $retry = ['_approval' => 'grant-token', 'status' => 'shipped', 'id' => 4];
        $verdict = $this->pipeline()->judge('demo/write', $retry, $this->owner(), Hints::none(), VerdictMode::Claim);
        $this->assertSame(Tripwires::REPEAT, $verdict->decision->reason);
    }

    public function testReadsAreNeverCounted(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue($this->pipeline()->judge('demo/read', ['id' => 4], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());
        }

        $this->assertSame([], $GLOBALS['wpas_test_transients'], 'no bucket was even created');
    }

    public function testARefusedRepeatDoesNotCount(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->pipeline()->judge('demo/write', ['id' => 4], $this->owner(), Hints::none(), VerdictMode::Claim);
        }

        $this->assertCount(3, $this->rows(Tripwires::REPEAT));
        $buckets = array_filter(
            $GLOBALS['wpas_test_transients'],
            static fn ($key): bool => str_starts_with((string) $key, 'agsafe_win_'),
            ARRAY_FILTER_USE_KEY,
        );
        $this->assertCount(1, $buckets);
        $this->assertSame(5, (int) reset($buckets)['value']);
    }

    public function testReChecksOfOneCallWithinARequestCountOnce(): void
    {
        add_filter(Tripwires::FILTER, static fn (array $limits): array => ['identical_calls_per_hour' => 2] + $limits);
        $request = $this->pipeline();

        for ($i = 0; $i < 11; $i++) {
            $this->assertTrue($request->judge('demo/write', ['id' => 4], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());
        }
        $this->assertTrue($this->pipeline()->judge('demo/write', ['id' => 4], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds(), 'second request');
        $this->assertFalse($this->pipeline()->judge('demo/write', ['id' => 4], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds(), 'third request');
    }

    public function testTheThresholdFilterIsHonouredAndAMalformedValueIsNot(): void
    {
        add_filter(Tripwires::FILTER, static fn (array $limits): array => ['identical_calls_per_hour' => 2] + $limits);
        $this->pipeline()->judge('demo/write', ['id' => 1], $this->owner(), Hints::none(), VerdictMode::Claim);
        $this->pipeline()->judge('demo/write', ['id' => 1], $this->owner(), Hints::none(), VerdictMode::Claim);
        $this->assertFalse($this->pipeline()->judge('demo/write', ['id' => 1], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());

        remove_all_filters(Tripwires::FILTER);
        add_filter(Tripwires::FILTER, static fn (array $limits): array => ['identical_calls_per_hour' => '2'] + $limits);
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->pipeline()->judge('demo/write', ['id' => 2], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds(), "call $i");
        }
        $this->assertFalse($this->pipeline()->judge('demo/write', ['id' => 2], $this->owner(), Hints::none(), VerdictMode::Claim)->proceeds());
    }
}
