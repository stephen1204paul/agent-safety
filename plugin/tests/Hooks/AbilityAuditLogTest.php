<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Plugin\Hooks\AbilityAuditLog;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * Exercises the governed-namespace gate behaviour (SPEC seam 6) on the
 * execution-audit hook: {@see AbilityAuditLog} must ignore any ability name
 * outside the injected namespace list, and ignore EVERYTHING when that list
 * is empty (a site with no integration active) -- same contract as {@see
 * \Specflux\AgentSafety\Plugin\Tests\Hooks\AbilityPermissionGateTest}.
 */
final class AbilityAuditLogTest extends TestCase
{
    private function log(InMemoryAuditSink $sink, array $governedNamespaces): AbilityAuditLog
    {
        return new AbilityAuditLog($sink, new TierClassifier(), new PackResolver(), $governedNamespaces);
    }

    public function testGovernedNamespaceIsAudited(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);

        $log->before('woocommerce/orders-list', ['id' => 1]);
        $log->after('woocommerce/orders-list', ['id' => 1], ['data' => ['status' => 200]]);

        $this->assertCount(1, $sink->records);
        $this->assertSame('woocommerce/orders-list', $sink->records[0]->toArray()['ability']);
        $this->assertSame('success', $sink->records[0]->toArray()['result']);
    }

    public function testUngovernedNamespaceIsIgnored(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);

        $log->before('core/something', ['id' => 1]);
        $log->after('core/something', ['id' => 1], ['data' => ['status' => 200]]);

        $this->assertCount(0, $sink->records);
    }

    public function testEmptyGovernedNamespacesIgnoresEverything(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, []);

        $log->before('woocommerce/orders-list', []);
        $log->after('woocommerce/orders-list', [], ['data' => ['status' => 200]]);

        $this->assertCount(0, $sink->records);
    }

    public function testFlushFailuresOnlyFlushesGovernedInFlightEntries(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);

        $log->before('woocommerce/orders-update', ['id' => 1]);
        // Never reaches after() -- e.g. a fatal -- so shutdown must flush it as a failure.
        $log->flushFailures();

        $this->assertCount(1, $sink->records);
        $this->assertSame('failure', $sink->records[0]->toArray()['result']);
    }
}
