<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Plugin\Hooks\AbilityAuditLog;
use Specflux\AgentSafety\Plugin\Hooks\McpRequestAuditHandler;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * Exercises the governed-namespace gate behaviour (SPEC seam 6) on the
 * execution-audit hook: {@see AbilityAuditLog} must ignore any ability name
 * outside the injected namespace list, and ignore EVERYTHING when that list
 * is empty (a site with no integration active) -- same contract as {@see
 * \Specflux\AgentSafety\Plugin\Tests\Hooks\AbilityPermissionGateTest}.
 *
 * Also exercises the double-logging dedupe against {@see McpRequestAuditHandler}'s
 * raw-args stash (class docblock: DOUBLE-LOGGING DEDUPE). Every test resets that
 * static stash in setUp/tearDown so it never leaks between tests (or from
 * {@see \Specflux\AgentSafety\Plugin\Tests\McpRequestAuditHandlerTest}, which
 * shares the same static state).
 */
final class AbilityAuditLogTest extends TestCase
{
    protected function setUp(): void
    {
        McpRequestAuditHandler::reset();
    }

    protected function tearDown(): void
    {
        McpRequestAuditHandler::reset();
    }

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

    /**
     * DOUBLE-LOGGING DEDUPE: with the MCP raw-args stash primed (simulating
     * McpRequestAuditHandler::captureArgs() having fired on
     * mcp_adapter_pre_tool_call for this same in-flight tools/call),
     * AbilityAuditLog::after() must write NOTHING -- McpRequestAuditHandler's
     * own (richer) mcp.request record supersedes it.
     */
    public function testAfterWritesNothingWhenAnMcpCaptureIsPending(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);
        McpRequestAuditHandler::captureArgs(['id' => 1], 'woocommerce-orders-list');

        $log->before('woocommerce/orders-list', ['id' => 1]);
        $log->after('woocommerce/orders-list', ['id' => 1], ['data' => ['status' => 200]]);

        $this->assertCount(0, $sink->records);
    }

    /**
     * With the stash empty (no adapter >= 0.5.0 pre_tool_call filter ever fired,
     * or simply a non-MCP execution path), after() behaves exactly as before --
     * this is the fallback case, not a special case.
     */
    public function testAfterWritesNormallyWhenNoMcpCaptureIsPending(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);

        $log->before('woocommerce/orders-list', ['id' => 1]);
        $log->after('woocommerce/orders-list', ['id' => 1], ['data' => ['status' => 200]]);

        $this->assertCount(1, $sink->records);
        $this->assertSame('success', $sink->records[0]->toArray()['result']);
    }

    /**
     * Once McpRequestAuditHandler::record_event() consumes the stash (simulated
     * here via consumeStash's public sibling captureArgs()/reset() pairing --
     * we drive the stash back to empty the same way record_event() would), a
     * SUBSEQUENT, unrelated non-MCP execution (e.g. a REST/wp-cli ability call
     * later in the same request) records normally again -- the suppression is
     * scoped to the in-flight window, not sticky for the rest of the request.
     */
    public function testAfterRecordsNormallyOnceTheStashHasBeenConsumed(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);

        McpRequestAuditHandler::captureArgs(['id' => 1], 'woocommerce-orders-list');
        // Simulate record_event() having consumed the one stashed entry for this
        // tool -- the stash for it is now empty (McpRequestAuditHandler has no
        // public "consume one" API beyond record_event() itself, and reset()
        // clears everything, which is the observable end state we need here).
        McpRequestAuditHandler::reset();

        $log->before('woocommerce/orders-update', ['id' => 2]);
        $log->after('woocommerce/orders-update', ['id' => 2], ['data' => ['status' => 200]]);

        $this->assertCount(1, $sink->records);
        $this->assertSame('success', $sink->records[0]->toArray()['result']);
    }

    /**
     * Ungoverned namespace stays ignored even while an MCP capture is pending --
     * the namespace gate is checked first, so a governed-but-unrelated stash
     * entry never masks this hook's own no-op for out-of-scope abilities.
     */
    public function testUngovernedNamespaceIsIgnoredEvenWithAPendingMcpCapture(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);
        McpRequestAuditHandler::captureArgs(['id' => 1], 'core-something');

        $log->before('core/something', ['id' => 1]);
        $log->after('core/something', ['id' => 1], ['data' => ['status' => 200]]);

        $this->assertCount(0, $sink->records);
    }

    /**
     * A skipped write must still pop the in-flight stack -- otherwise
     * flushFailures() at shutdown would find the already-succeeded, deduped
     * call still "in flight" and wrongly record it as a failure (see class
     * docblock: skipping the write must not skip popInFlight()).
     */
    public function testDedupedSuccessDoesNotResurfaceAsAFailureAtShutdown(): void
    {
        $sink = new InMemoryAuditSink();
        $log = $this->log($sink, ['woocommerce/']);
        McpRequestAuditHandler::captureArgs(['id' => 1], 'woocommerce-orders-list');

        $log->before('woocommerce/orders-list', ['id' => 1]);
        $log->after('woocommerce/orders-list', ['id' => 1], ['data' => ['status' => 200]]);
        // The MCP call's mcp.request event has "already fired" (stash consumed);
        // shutdown must find nothing left in flight for this ability.
        McpRequestAuditHandler::reset();
        $log->flushFailures();

        $this->assertCount(0, $sink->records);
    }
}
