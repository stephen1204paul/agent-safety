<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\HashChain;
use Specflux\AgentSafety\Audit\Redactor;
use Specflux\AgentSafety\Gate\Outcome;

final class AuditTest extends TestCase
{
    private function record(string $id, string $ability, AuditDecision $decision): AuditRecord
    {
        return AuditRecord::decision(
            id: $id,
            ts: '2026-06-25T10:00:00Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => 'ck_abc', 'wp_user' => 7],
            ability: $ability,
            tier: 2,
            input: ['order' => 123],
            decision: $decision,
        );
    }

    /** @return list<array{prev_hash: string, canonical_json: string, entry_hash: string}> */
    private function chainFrom(AuditRecord ...$records): array
    {
        $entries = [];
        $prev = HashChain::GENESIS;
        foreach ($records as $r) {
            $json = $r->canonicalJson();
            $hash = HashChain::entryHash($prev, $json);
            $entries[] = ['prev_hash' => $prev, 'canonical_json' => $json, 'entry_hash' => $hash];
            $prev = $hash;
        }

        return $entries;
    }

    public function testRecordShapeMatchesSpec(): void
    {
        $arr = $this->record('evt_1', 'woocommerce/orders-update', AuditDecision::Pending)->toArray();

        // The canonical audit-record field set, in order.
        $this->assertSame(
            ['id', 'ts', 'correlation_id', 'pack', 'actor', 'ability', 'tier', 'input', 'dry_run', 'decision', 'approval', 'result', 'external_effects', 'ip'],
            array_keys($arr),
        );
        $this->assertSame('pending', $arr['decision']);
        $this->assertNull($arr['result']);
        $this->assertFalse($arr['dry_run']);
    }

    public function testExecutionRecordCarriesResult(): void
    {
        $rec = AuditRecord::execution(
            id: 'evt_2',
            ts: '2026-06-25T10:00:01Z',
            correlationId: 'sess_test',
            pack: 'default-agent',
            actor: ['token_id' => null, 'wp_user' => 7],
            ability: 'woocommerce/products-list',
            tier: 0,
            input: [],
            result: 'success',
        );

        $this->assertSame('allowed', $rec->toArray()['decision']);
        $this->assertSame('success', $rec->toArray()['result']);
    }

    public function testDecisionMapsFromOutcome(): void
    {
        $this->assertSame(AuditDecision::Allowed, AuditDecision::fromOutcome(Outcome::Allow));
        $this->assertSame(AuditDecision::Denied, AuditDecision::fromOutcome(Outcome::Deny));
        $this->assertSame(AuditDecision::Pending, AuditDecision::fromOutcome(Outcome::ApprovalRequired));
    }

    public function testValidChainVerifies(): void
    {
        $entries = $this->chainFrom(
            $this->record('evt_1', 'woocommerce/products-list', AuditDecision::Allowed),
            $this->record('evt_2', 'woocommerce/orders-update', AuditDecision::Pending),
            $this->record('evt_3', 'woocommerce/orders-update', AuditDecision::Approved),
        );

        $this->assertTrue(HashChain::verify($entries));
    }

    public function testAlteredRecordBreaksChain(): void
    {
        $entries = $this->chainFrom(
            $this->record('evt_1', 'woocommerce/products-list', AuditDecision::Allowed),
            $this->record('evt_2', 'woocommerce/orders-update', AuditDecision::Pending),
        );

        // Tamper: rewrite the second record's stored JSON (hide the refund).
        $entries[1]['canonical_json'] = str_replace('orders-update', 'products-list', $entries[1]['canonical_json']);

        $this->assertFalse(HashChain::verify($entries));
    }

    public function testDeletedRecordBreaksChain(): void
    {
        $entries = $this->chainFrom(
            $this->record('evt_1', 'woocommerce/products-list', AuditDecision::Allowed),
            $this->record('evt_2', 'woocommerce/orders-update', AuditDecision::Pending),
            $this->record('evt_3', 'woocommerce/orders-update', AuditDecision::Approved),
        );

        // Remove the middle entry; the third's prev_hash no longer lines up.
        unset($entries[1]);
        $entries = array_values($entries);

        $this->assertFalse(HashChain::verify($entries));
    }

    public function testRedactorMasksPiiWhenEnabled(): void
    {
        $input = [
            'order' => 123,
            'billing' => ['email' => 'a@b.com', 'first_name' => 'Ada', 'city' => 'Ipoh'],
        ];

        $out = Redactor::apply($input, true);

        $this->assertSame('«redacted»', $out['billing']['email']);
        $this->assertSame('«redacted»', $out['billing']['first_name']);
        $this->assertSame('Ipoh', $out['billing']['city']); // not on the denylist
        $this->assertSame(123, $out['order']);
    }

    public function testRedactorNoOpWhenDisabled(): void
    {
        $input = ['billing' => ['email' => 'a@b.com']];
        $this->assertSame($input, Redactor::apply($input, false));
    }
}
