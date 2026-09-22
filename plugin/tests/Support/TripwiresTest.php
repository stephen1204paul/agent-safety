<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\ApprovalNotifier;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\Tripwires;
use Specflux\AgentSafety\Plugin\Support\WindowCounter;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * The tripwires on their own: threshold parsing, the per-request memos, what
 * a lockout leaves behind (row, action, email) and what the repeat guard
 * treats as one call. A fresh instance stands for a fresh request. How the
 * pipeline routes decisions into them is proven in
 * {@see \Specflux\AgentSafety\Plugin\Tests\Verdict\VerdictPipelineStopsTest}.
 */
final class TripwiresTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private InMemoryAuditSink $sink;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        remove_all_filters(Tripwires::FILTER);
        RequestContext::reset();

        $this->sink = new InMemoryAuditSink();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_transients'] = [];
        $GLOBALS['wpas_test_actions'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        remove_all_filters(Tripwires::FILTER);
        RequestContext::reset();
    }

    private function tripwires(): Tripwires
    {
        return new Tripwires(new WindowCounter(), new AdminChangeRecorder($this->sink), new ApprovalNotifier());
    }

    /** @param array<string, mixed> $limits */
    private function limit(array $limits): void
    {
        add_filter(Tripwires::FILTER, static fn (array $defaults): array => $limits + $defaults);
    }

    /** The counter buckets currently stored, value by key. @return array<string, int> */
    private function buckets(): array
    {
        $buckets = [];
        foreach ($GLOBALS['wpas_test_transients'] as $key => $row) {
            if (str_starts_with((string) $key, 'agsafe_win_')) {
                $buckets[(string) $key] = (int) $row['value'];
            }
        }

        return $buckets;
    }

    // --- thresholds ----------------------------------------------------------

    public function testTheDefaultsApplyWithoutTheFilter(): void
    {
        $this->assertSame(Tripwires::DEFAULTS, $this->tripwires()->limits());
    }

    public function testTheFilterSetsEachThresholdAndAMalformedValueKeepsThatKeysDefault(): void
    {
        add_filter(Tripwires::FILTER, static fn (): array => [
            'denials_per_minute' => 3,
            'lockout_seconds' => '30',
            'identical_calls_per_hour' => 0,
        ]);
        $this->assertSame(
            ['denials_per_minute' => 3, 'lockout_seconds' => 600, 'identical_calls_per_hour' => 5],
            $this->tripwires()->limits(),
        );

        remove_all_filters(Tripwires::FILTER);
        add_filter(Tripwires::FILTER, static fn (): array => ['denials_per_minute' => -1, 'identical_calls_per_hour' => 2.0]);
        $this->assertSame(Tripwires::DEFAULTS, $this->tripwires()->limits());

        remove_all_filters(Tripwires::FILTER);
        add_filter(Tripwires::FILTER, static fn (): string => 'nonsense');
        $this->assertSame(Tripwires::DEFAULTS, $this->tripwires()->limits());
    }

    // --- denial lockout ------------------------------------------------------

    public function testDenialsBelowTheThresholdLockNothing(): void
    {
        $tripwires = $this->tripwires();
        for ($i = 0; $i < 19; $i++) {
            $tripwires->recordDenial('tok', 'ns/verb', ['call' => $i]);
        }

        $this->assertFalse($tripwires->isLockedOut('tok'));
        $this->assertSame([], $this->sink->records);
        $this->assertSame([], $GLOBALS['wpas_test_actions']);
        $this->assertSame([], $GLOBALS['wpas_test_mail']);
    }

    public function testTheTwentiethDenialLocksAuditsFiresAndEmailsExactlyOnce(): void
    {
        $GLOBALS['wpas_test_options']['admin_email'] = 'owner@example.test';
        $tripwires = $this->tripwires();
        for ($i = 0; $i < 20; $i++) {
            $tripwires->recordDenial('tok', 'ns/verb', ['call' => $i]);
        }

        $this->assertTrue($tripwires->isLockedOut('tok'));
        $this->assertFalse($tripwires->isLockedOut('other'));

        $this->assertCount(1, $this->sink->records);
        $row = $this->sink->records[0]->toArray();
        $this->assertSame(AdminChangeRecorder::EVENT_TRIPWIRE_LOCKED, $row['reason']);
        $this->assertSame('admin', $row['decision']);
        $this->assertSame(AdminChangeRecorder::PACK, $row['pack']);
        $this->assertSame(Tripwires::LOCKOUT, $row['ability']);
        $this->assertSame(['token' => 'tok', 'denials' => 20, 'lockout_seconds' => 600], $row['input']);

        $details = ['token' => 'tok', 'denials' => 20, 'lockout_seconds' => 600, 'until' => self::NOW + 600];
        $this->assertSame([[Tripwires::ACTION, 'tok', Tripwires::LOCKOUT, $details]], $GLOBALS['wpas_test_actions']);

        $this->assertCount(1, $GLOBALS['wpas_test_mail']);
        $mail = $GLOBALS['wpas_test_mail'][0];
        $this->assertSame('owner@example.test', $mail['to']);
        $this->assertStringStartsWith('[Agent Safety]', $mail['subject']);
        $this->assertStringContainsString('tok', $mail['subject']);
        $this->assertStringContainsString('600 seconds', $mail['message']);
        $this->assertStringContainsString('tools.php?page=agent-safety-audit', $mail['message']);
    }

    public function testTheConfiguredRecipientBeatsAdminEmailAndNoRecipientMeansNoMail(): void
    {
        $GLOBALS['wpas_test_options']['admin_email'] = 'owner@example.test';
        $GLOBALS['wpas_test_options'][ApprovalNotifier::EMAIL_OPTION] = 'security@example.test';
        $tripwires = $this->tripwires();
        for ($i = 0; $i < 20; $i++) {
            $tripwires->recordDenial('tok', 'ns/verb', ['call' => $i]);
        }
        $this->assertSame('security@example.test', $GLOBALS['wpas_test_mail'][0]['to']);

        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $tripwires = $this->tripwires();
        for ($i = 0; $i < 20; $i++) {
            $tripwires->recordDenial('other', 'ns/verb', ['call' => $i]);
        }
        $this->assertTrue($tripwires->isLockedOut('other'), 'the lockout does not depend on the mail');
        $this->assertSame([], $GLOBALS['wpas_test_mail']);
    }

    public function testDenialsWhileLockedOutAreNeitherCountedNorRetripped(): void
    {
        $tripwires = $this->tripwires();
        for ($i = 0; $i < 20; $i++) {
            $tripwires->recordDenial('tok', 'ns/verb', ['call' => $i]);
        }
        for ($i = 20; $i < 30; $i++) {
            $this->tripwires()->recordDenial('tok', 'ns/verb', ['call' => $i]);
        }

        $this->assertSame([20], array_values($this->buckets()), 'the minute bucket stopped at the trip');
        $this->assertCount(1, $this->sink->records);
        $this->assertCount(1, $GLOBALS['wpas_test_actions']);
    }

    public function testTheLockoutLapsesAfterLockoutSeconds(): void
    {
        $this->limit(['lockout_seconds' => 30]);
        $tripwires = $this->tripwires();
        for ($i = 0; $i < 20; $i++) {
            $tripwires->recordDenial('tok', 'ns/verb', ['call' => $i]);
        }

        $GLOBALS['wpas_test_time'] = self::NOW + 29;
        $this->assertTrue($this->tripwires()->isLockedOut('tok'));

        $GLOBALS['wpas_test_time'] = self::NOW + 30;
        $this->assertFalse($this->tripwires()->isLockedOut('tok'));
    }

    public function testARecheckOfOneDeniedCallWithinARequestCountsOnce(): void
    {
        $this->limit(['denials_per_minute' => 2]);
        $request = $this->tripwires();
        for ($i = 0; $i < 11; $i++) {
            $request->recordDenial('tok', 'ns/verb', ['id' => 7]);
        }
        $this->assertFalse($request->isLockedOut('tok'), 'eleven re-checks of one call are one denial');

        $request->recordDenial('tok', 'ns/verb', ['id' => 8]);
        $this->assertTrue($request->isLockedOut('tok'));
    }

    public function testAnonymousCallersShareOneBucket(): void
    {
        $this->limit(['denials_per_minute' => 2]);
        $tripwires = $this->tripwires();
        $tripwires->recordDenial(null, 'ns/verb', ['call' => 1]);
        $tripwires->recordDenial(null, 'ns/verb', ['call' => 2]);

        $this->assertTrue($tripwires->isLockedOut(null));
        $this->assertFalse($tripwires->isLockedOut('tok'));
    }

    // --- repeat-call guard ---------------------------------------------------

    public function testIdenticalCallsAreAdmittedUpToTheThresholdThenRefusedWithoutCounting(): void
    {
        $pack = new Pack(name: 'p', allow: ['*']);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]), "call $i");
        }
        $this->assertFalse($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]));
        $this->assertFalse($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]));

        $this->assertSame([5], array_values($this->buckets()), 'the two refusals were not counted');
    }

    public function testTheApprovalTokenAndKeyOrderDoNotChangeCallIdentity(): void
    {
        $pack = new Pack(name: 'p', allow: ['*']);
        for ($i = 0; $i < 5; $i++) {
            $this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1, 'status' => 'shipped']);
        }

        $retry = ['_approval' => 'grant-token', 'status' => 'shipped', 'id' => 1];
        $this->assertFalse($this->tripwires()->admit($pack, 'tok', 'ns/verb', $retry));
        $this->assertCount(1, $this->buckets());
    }

    public function testArgumentsPackTokenAndVerbEachDistinguishACall(): void
    {
        $pack = new Pack(name: 'p', allow: ['*']);
        for ($i = 0; $i < 5; $i++) {
            $this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]);
        }

        $this->assertTrue($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 2]));
        $this->assertTrue($this->tripwires()->admit($pack, 'tok', 'ns/other', ['id' => 1]));
        $this->assertTrue($this->tripwires()->admit($pack, 'other', 'ns/verb', ['id' => 1]));
        $this->assertTrue($this->tripwires()->admit(new Pack(name: 'q', allow: ['*']), 'tok', 'ns/verb', ['id' => 1]));
        $this->assertFalse($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]));
    }

    public function testARecheckOfOneCallWithinARequestCountsOnce(): void
    {
        $this->limit(['identical_calls_per_hour' => 2]);
        $pack = new Pack(name: 'p', allow: ['*']);

        $request = $this->tripwires();
        for ($i = 0; $i < 11; $i++) {
            $this->assertTrue($request->admit($pack, 'tok', 'ns/verb', ['id' => 1]));
        }
        $this->assertTrue($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]), 'second request');
        $this->assertFalse($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]), 'third request');
    }

    public function testTheHourWindowRollsOver(): void
    {
        $pack = new Pack(name: 'p', allow: ['*']);
        for ($i = 0; $i < 5; $i++) {
            $this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]);
        }
        $this->assertFalse($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]));

        $GLOBALS['wpas_test_time'] = self::NOW + 3600;
        $this->assertTrue($this->tripwires()->admit($pack, 'tok', 'ns/verb', ['id' => 1]));
    }
}
