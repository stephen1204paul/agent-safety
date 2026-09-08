<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * Shadow mode is the one feature that loosens a verdict, so every path that
 * could keep a pack shadowed — the option, the filter, a save, the sweep —
 * is held to the same rule: an int expiry in the future and within MAX_TTL,
 * or nothing. The clock is frozen through tests/stubs/wpas-clock.php.
 */
final class ShadowModeTest extends TestCase
{
    private const NOW = 1_800_000_000;

    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = self::NOW;
        remove_all_filters(ShadowMode::FILTER);
        RequestContext::reset();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_time'] = \time();
        remove_all_filters(ShadowMode::FILTER);
        RequestContext::reset();
    }

    // --- reading -------------------------------------------------------------

    public function testNoPackIsShadowedByDefault(): void
    {
        $shadow = new ShadowMode();

        $this->assertSame([], $shadow->packs());
        $this->assertFalse($shadow->isShadow('default-agent'));
    }

    public function testMaxTtlIsSevenDays(): void
    {
        $this->assertSame(7 * 24 * 60 * 60, ShadowMode::MAX_TTL);
    }

    public function testAFutureExpiryShadowsThePack(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent' => self::NOW + 3600];
        $shadow = new ShadowMode();

        $this->assertTrue($shadow->isShadow('support-agent'));
        $this->assertFalse($shadow->isShadow('default-agent'));
        $this->assertSame(['support-agent'], $shadow->packs());
        $this->assertSame(['support-agent' => self::NOW + 3600], $shadow->expiries());
    }

    public function testAnExpiryMustBeStrictlyInTheFuture(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = [
            'lapsed' => self::NOW - 1,
            'now' => self::NOW,
            'soon' => self::NOW + 1,
        ];

        $this->assertSame(['soon'], (new ShadowMode())->packs());
    }

    public function testAnExpiryBeyondMaxTtlIsNotHonoured(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = [
            'at-ceiling' => self::NOW + ShadowMode::MAX_TTL,
            'past-ceiling' => self::NOW + ShadowMode::MAX_TTL + 1,
            'forever' => PHP_INT_MAX,
        ];

        $this->assertSame(['at-ceiling'], (new ShadowMode())->packs());
    }

    public function testTheLegacyListShapeShadowsNothing(): void
    {
        // Pre-expiry storage: a plain list of names. Fails closed until the
        // schema upgrade converts it (see SchemaTest).
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent', 'owner'];

        $this->assertSame([], (new ShadowMode())->packs());
    }

    public function testNonIntStampsAreDroppedNotCoerced(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = [
            'numeric-string' => (string) (self::NOW + 3600),
            'float' => (float) (self::NOW + 3600),
            'bool' => true,
            'null' => null,
            '' => self::NOW + 3600,
        ];

        $this->assertSame([], (new ShadowMode())->packs());
    }

    public function testAMalformedOptionFailsClosedToNoShadowing(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = 'support-agent'; // not an array

        $this->assertSame([], (new ShadowMode())->packs());
    }

    public function testTheFilterIsHeldToTheSameRuleAsTheOption(): void
    {
        add_filter(ShadowMode::FILTER, static fn (): array => [
            'valid' => self::NOW + 60,
            'forever' => PHP_INT_MAX,
            'lapsed' => self::NOW - 5,
            'legacy-entry',
            'string-stamp' => (string) (self::NOW + 60),
        ]);

        $this->assertSame(['valid' => self::NOW + 60], (new ShadowMode())->expiries());
    }

    public function testTheFilterReceivesTheValidatedStoredMap(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['live' => self::NOW + 60, 'stale' => self::NOW - 1];
        $received = null;
        add_filter(ShadowMode::FILTER, static function (array $packs) use (&$received): array {
            $received = $packs;

            return $packs;
        });

        (new ShadowMode())->packs();

        $this->assertSame(['live' => self::NOW + 60], $received);
    }

    public function testAFilterReturningANonArrayShadowsNothing(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['live' => self::NOW + 60];
        add_filter(ShadowMode::FILTER, static fn (): string => 'live');

        $this->assertSame([], (new ShadowMode())->packs());
    }

    // --- apply (the admin save) ----------------------------------------------

    public function testApplyShadowsANewlyTickedPackForTheTtl(): void
    {
        $changed = (new ShadowMode())->apply(['support-agent'], 3600);

        $this->assertSame(['support-agent' => self::NOW + 3600], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame(['support-agent' => self::NOW + 3600], $changed['enabled']);
        $this->assertSame([], $changed['disabled']);
        $this->assertSame([], $changed['expired']);
    }

    public function testApplyKeepsAnExistingWindowRatherThanExtendingIt(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent' => self::NOW + 100];

        $changed = (new ShadowMode())->apply(['support-agent'], 3600);

        $this->assertSame(['support-agent' => self::NOW + 100], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame([], $changed['enabled']);
    }

    public function testApplyRemovesAnUntickedPack(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['keep' => self::NOW + 100, 'drop' => self::NOW + 100];

        $changed = (new ShadowMode())->apply(['keep'], 3600);

        $this->assertSame(['keep' => self::NOW + 100], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame(['drop'], $changed['disabled']);
    }

    public function testApplyClampsTheTtlToTheCeiling(): void
    {
        $changed = (new ShadowMode())->apply(['support-agent'], ShadowMode::MAX_TTL * 4);

        $this->assertSame(['support-agent' => self::NOW + ShadowMode::MAX_TTL], $changed['enabled']);
        $this->assertTrue((new ShadowMode())->isShadow('support-agent'));
    }

    public function testApplyWithANonPositiveTtlEnablesNothing(): void
    {
        $changed = (new ShadowMode())->apply(['support-agent'], 0);

        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame([], $changed['enabled']);
    }

    public function testApplyReportsLapsedEntriesItSweptUp(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['old' => self::NOW - 1];

        $changed = (new ShadowMode())->apply([], 3600);

        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame(['old' => self::NOW - 1], $changed['expired']);
        $this->assertSame([], $changed['disabled'], 'a lapsed entry was not disabled by a human');
    }

    public function testApplyReportsARetickedLapsedPackAsExpiredThenEnabled(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['old' => self::NOW - 1];

        $changed = (new ShadowMode())->apply(['old'], 60);

        $this->assertSame(['old' => self::NOW - 1], $changed['expired']);
        $this->assertSame(['old' => self::NOW + 60], $changed['enabled']);
        $this->assertSame(['old' => self::NOW + 60], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    // --- sweep (the hourly cron) ---------------------------------------------

    public function testSweepDropsEveryEntryThatNoLongerShadowsAndAuditsEach(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = [
            'live' => self::NOW + 60,
            'gone' => self::NOW - 1,
            'far' => self::NOW + ShadowMode::MAX_TTL + 1,
            'legacy-entry',
        ];
        $sink = new InMemoryAuditSink();

        (new ShadowMode())->sweep(new AdminChangeRecorder($sink));

        $this->assertSame(['live' => self::NOW + 60], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertCount(3, $sink->records);

        $byPack = [];
        foreach ($sink->records as $record) {
            $row = $record->toArray();
            $this->assertSame(AdminChangeRecorder::EVENT_SHADOW_EXPIRED, $row['reason']);
            $this->assertSame('admin', $row['decision']);
            $this->assertSame(AdminChangeRecorder::PACK, $row['pack']);
            $this->assertSame(ShadowMode::OPTION, $row['ability']);
            $byPack[$row['input']['pack']] = $row['input']['expires_at'];
        }
        $this->assertSame([
            'gone' => self::NOW - 1,
            'far' => self::NOW + ShadowMode::MAX_TTL + 1,
            'legacy-entry' => null,
        ], $byPack);
    }

    public function testSweepLeavesAHealthyOptionAloneAndWritesNothing(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['live' => self::NOW + 60];
        $sink = new InMemoryAuditSink();

        (new ShadowMode())->sweep(new AdminChangeRecorder($sink));

        $this->assertSame([], $sink->records);
        $this->assertSame(['live' => self::NOW + 60], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    public function testSweepLeavesAMalformedOptionForTheReaderToFailClosedOn(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = 'garbage';
        $sink = new InMemoryAuditSink();

        (new ShadowMode())->sweep(new AdminChangeRecorder($sink));

        $this->assertSame([], $sink->records);
        $this->assertSame('garbage', $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    // --- migrateLegacy (the schema upgrade) ----------------------------------

    public function testMigrateLegacyGivesEachListedPackAFullWindowFromNow(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent', 'owner', 7, '', null];

        (new ShadowMode())->migrateLegacy();

        $this->assertSame([
            'support-agent' => self::NOW + ShadowMode::MAX_TTL,
            'owner' => self::NOW + ShadowMode::MAX_TTL,
        ], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
        $this->assertSame(['support-agent', 'owner'], (new ShadowMode())->packs());
    }

    public function testMigrateLegacyLeavesAnAlreadyMigratedMapAlone(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = ['support-agent' => self::NOW - 1];

        (new ShadowMode())->migrateLegacy();

        // Even a lapsed map entry is not refreshed: migration converts shape,
        // it never hands out a new window.
        $this->assertSame(['support-agent' => self::NOW - 1], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }

    public function testMigrateLegacyLeavesANonArrayAndAnEmptyListAlone(): void
    {
        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = 'garbage';
        (new ShadowMode())->migrateLegacy();
        $this->assertSame('garbage', $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);

        $GLOBALS['wpas_test_options'][ShadowMode::OPTION] = [];
        (new ShadowMode())->migrateLegacy();
        $this->assertSame([], $GLOBALS['wpas_test_options'][ShadowMode::OPTION]);
    }
}
