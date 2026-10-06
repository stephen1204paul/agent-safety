<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use CounterTableWpdb;
use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\AtomicCounterStore;

/**
 * The shared counter table's contract, against {@see CounterTableWpdb}, which
 * models INSERT ... ON DUPLICATE KEY UPDATE and expiry. The clock is the
 * Support-namespace time() override (tests/stubs/wpas-clock.php).
 */
final class AtomicCounterStoreTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private CounterTableWpdb $db;

    protected function setUp(): void
    {
        $this->db = CounterTableWpdb::install();
        $GLOBALS['wpas_test_time'] = self::NOW;
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpas_test_time'] = \time();
    }

    public function testAddCreatesTheRowThenAccumulatesAndReturnsTheNewValue(): void
    {
        $store = new AtomicCounterStore();

        $this->assertSame(2.0, $store->add('k', 2.0, 60));
        $this->assertSame(5.5, $store->add('k', 3.5, 60));
        $this->assertSame(5.5, $store->get('k'));
    }

    public function testANegativeDeltaReleasesWhatWasAdded(): void
    {
        $store = new AtomicCounterStore();
        $store->add('k', 1.0, 60);
        $store->add('k', 1.0, 60);

        $this->assertSame(1.0, $store->add('k', -1.0, 60));
    }

    public function testAddIsASingleAtomicUpsertStatement(): void
    {
        (new AtomicCounterStore())->add('k', 1.0, 60);

        $upserts = array_values(array_filter(
            $this->db->queries,
            static fn (string $q): bool => str_starts_with($q, 'INSERT INTO'),
        ));
        $this->assertCount(1, $upserts);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE value = value + 1.000000,', $upserts[0]);
        $this->assertStringContainsString('expires_at = GREATEST(expires_at, ', $upserts[0]);
        $this->assertStringNotContainsString('VALUES(', $upserts[0]);
    }

    public function testTheExpiryOnlyMovesForward(): void
    {
        $store = new AtomicCounterStore();
        $store->add('k', 1.0, 600);
        $store->add('k', -1.0, 60);

        $this->assertSame(self::NOW + 600, $this->db->rows['k']['expires_at']);
    }

    public function testGetIgnoresExpiredRows(): void
    {
        $store = new AtomicCounterStore();
        $store->add('k', 4.0, 60);

        $GLOBALS['wpas_test_time'] = self::NOW + 59;
        $this->assertSame(4.0, $store->get('k'));

        $GLOBALS['wpas_test_time'] = self::NOW + 60;
        $this->assertSame(0.0, $store->get('k'), 'a row whose expires_at has been reached no longer counts');
        $this->assertArrayHasKey('k', $this->db->rows, 'ignored, not yet swept');
    }

    public function testGetOfAnUnknownKeyIsZero(): void
    {
        $this->assertSame(0.0, (new AtomicCounterStore())->get('nope'));
    }

    public function testPurgeExpiredRemovesOnlyExpiredRows(): void
    {
        $store = new AtomicCounterStore();
        $store->add('old', 1.0, 60);
        $store->add('live', 1.0, 600);

        $GLOBALS['wpas_test_time'] = self::NOW + 61;
        $store->purgeExpired();

        $this->assertArrayNotHasKey('old', $this->db->rows);
        $this->assertArrayHasKey('live', $this->db->rows);
    }

    public function testTheTableIsCreatedLazilyOncePerStore(): void
    {
        $store = new AtomicCounterStore();
        $store->add('k', 1.0, 60);
        $store->get('k');

        $creates = array_filter($this->db->queries, static fn (string $q): bool => str_starts_with($q, 'CREATE TABLE IF NOT EXISTS wp_agsafe_counters'));
        $this->assertCount(1, $creates);
    }
}
