<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Privacy;

use InMemoryAuditRowsWpdb;
use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Audit\HashChain;
use Specflux\AgentSafety\Plugin\Privacy\PersonalDataExporter;
use Specflux\AgentSafety\Plugin\Privacy\PrivacyAuditReader;

/**
 * §3.6 item 2 / §4 row 9: the exporter must resolve the requested email to a
 * WP user and return ONLY that user's audit rows, matching on the recorded
 * `wp_user` column (which mirrors the audit record's `actor.wp_user`) —
 * never on `input`, even when a row's input happens to contain the
 * requester's own email address but belongs to a different actor.
 */
final class PersonalDataExporterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_users_by_email'] = [];
    }

    public function testExportsOnlyTheRequestingUsersRowsAndNeverMatchesOnInput(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(self::chain([
            ['wp_user' => 5, 'ability' => 'core/read-post', 'input' => []],
            ['wp_user' => 6, 'ability' => 'core/read-post', 'input' => []],
            // Belongs to user 6, but its INPUT contains user 5's email —
            // must never be matched into user 5's export.
            ['wp_user' => 6, 'ability' => 'core/update-post', 'input' => ['email' => 'alice@example.com']],
        ]));

        $GLOBALS['wpas_test_users_by_email']['alice@example.com'] = 5;

        $exporter = new PersonalDataExporter(new PrivacyAuditReader($db));
        $result = $exporter->export('alice@example.com');

        $this->assertTrue($result['done']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('agent-safety-audit-log-1', $result['data'][0]['item_id']);
        $this->assertSame('core/read-post', $result['data'][0]['data'][1]['value']);
    }

    public function testUnknownEmailReturnsNoDataDoneTrue(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(self::chain([
            ['wp_user' => 5, 'ability' => 'core/read-post', 'input' => []],
        ]));

        $exporter = new PersonalDataExporter(new PrivacyAuditReader($db));
        $result = $exporter->export('nobody@example.com');

        $this->assertSame(['data' => [], 'done' => true], $result);
    }

    public function testPagesAcrossABatchBoundary(): void
    {
        $specs = [];
        for ($i = 0; $i < 205; $i++) {
            $specs[] = ['wp_user' => 9, 'ability' => 'core/read-post', 'input' => []];
        }

        $db = new InMemoryAuditRowsWpdb();
        $db->seed(self::chain($specs));
        $GLOBALS['wpas_test_users_by_email']['bob@example.com'] = 9;

        $exporter = new PersonalDataExporter(new PrivacyAuditReader($db));

        $page1 = $exporter->export('bob@example.com', 1);
        $this->assertFalse($page1['done']);
        $this->assertCount(200, $page1['data']);
        $this->assertSame('agent-safety-audit-log-1', $page1['data'][0]['item_id']);

        $page2 = $exporter->export('bob@example.com', 2);
        $this->assertTrue($page2['done']);
        $this->assertCount(5, $page2['data']);
        $this->assertSame('agent-safety-audit-log-201', $page2['data'][0]['item_id']);
    }

    /**
     * Builds a valid hash chain of audit-log rows from short specs.
     *
     * @param list<array{wp_user: int, ability: string, input: array<string, mixed>}> $specs
     * @return list<array<string, mixed>>
     */
    public static function chain(array $specs): array
    {
        $rows = [];
        $prev = HashChain::GENESIS;
        $id = 1;

        foreach ($specs as $spec) {
            $record = [
                'id' => 'evt-' . $id,
                'ts' => '2026-09-01T00:00:00Z',
                'correlation_id' => 'corr-' . $id,
                'pack' => 'default',
                'actor' => ['token_id' => null, 'wp_user' => $spec['wp_user']],
                'ability' => $spec['ability'],
                'tier' => 1,
                'input' => $spec['input'],
                'dry_run' => false,
                'decision' => 'allowed',
                'reason' => null,
                'approval' => null,
                'result' => 'success',
                'external_effects' => [],
                'ip' => '10.0.0.1',
            ];
            $json = (string) json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $entryHash = HashChain::entryHash($prev, $json);

            $rows[] = [
                'id' => $id,
                'wp_user' => $spec['wp_user'],
                'record_json' => $json,
                'prev_hash' => $prev,
                'entry_hash' => $entryHash,
            ];

            $prev = $entryHash;
            $id++;
        }

        return $rows;
    }
}
