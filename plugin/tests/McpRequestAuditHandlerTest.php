<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Plugin\Hooks\McpRequestAuditHandler;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Tests\Fakes\InMemoryAuditSink;

/**
 * Exercises {@see McpRequestAuditHandler} against the six `mcp.request` tag
 * shapes empirically captured against mcp-adapter upstream HEAD (see the
 * handler's class docblock + UPSTREAM_ISSUE_audit_hook.md). Proves this
 * plugin can build a full audit trail purely from
 * `McpObservabilityHandlerInterface::record_event()` — no adapter changes.
 */
final class McpRequestAuditHandlerTest extends TestCase
{
    private InMemoryAuditSink $sink;
    private McpRequestAuditHandler $handler;

    protected function setUp(): void
    {
        McpRequestAuditHandler::reset();
        $this->sink = new InMemoryAuditSink();
        McpRequestAuditHandler::configure($this->sink, new PackResolver());
        $this->handler = new McpRequestAuditHandler();
        $GLOBALS['wpas_test_current_user_id'] = 0;
    }

    protected function tearDown(): void
    {
        McpRequestAuditHandler::reset();
    }

    /** @return array<string, mixed> */
    private static function commonTags(string $toolName, string $abilityName, int $requestId, string $status, ?string $failureReason = null): array
    {
        $tags = [
            'method' => 'tools/call',
            'transport' => 'test-transport',
            'server_id' => 'verify-srv',
            'params' => [
                'name' => $toolName,
                'arguments_count' => 2,
                'arguments_keys' => ['[REDACTED]', 'note'],
            ],
            'request_id' => $requestId,
            'session_id' => null,
            'component_type' => 'tool',
            'tool_name' => $toolName,
            'ability_name' => $abilityName,
            'source' => 'ability',
            'status' => $status,
        ];

        if ($failureReason !== null) {
            $tags['failure_reason'] = $failureReason;
        }

        return $tags;
    }

    public function testSuccessWithPrimedStashProducesExecutionRecordWithRawArgs(): void
    {
        $rawArgs = ['api_key' => 'sekret', 'note' => 'hi'];
        McpRequestAuditHandler::captureArgs($rawArgs, 'test-verify-success');
        $GLOBALS['wpas_test_current_user_id'] = 42;

        $tags = self::commonTags('test-verify-success', 'test/verify-success', 101, 'success');
        $this->handler->record_event('mcp.request', $tags, 12.5);

        $this->assertCount(1, $this->sink->records);
        $record = $this->sink->records[0]->toArray();

        $this->assertSame('allowed', $record['decision']);
        $this->assertSame('success', $record['result']);
        $this->assertSame('test/verify-success', $record['ability']);
        $this->assertSame(42, $record['actor']['wp_user']);
        $this->assertSame($rawArgs, $record['input']['args']);
        $this->assertArrayNotHasKey('_raw_args_unavailable', $record['input']['args']);
        $this->assertSame(101, $record['input']['_mcp']['request_id']);
        $this->assertSame('test-verify-success', $record['input']['_mcp']['tool_name']);
        $this->assertSame(12.5, $record['input']['_mcp']['duration_ms']);
        // 'session_id' was null in tags -> filtered out of the metadata, not stored as null.
        $this->assertArrayNotHasKey('session_id', $record['input']['_mcp']);
    }

    public function testPermissionDeniedBoolFalseProducesDeniedDecisionWithNoRawArgsMarker(): void
    {
        // No captureArgs() call: the permission check runs BEFORE
        // mcp_adapter_pre_tool_call, so a denied call's raw args are never
        // observable through the public API at all (the acknowledged
        // upstream gap this handler documents rather than papers over).
        $tags = self::commonTags('test-verify-denied-bool', 'test/verify-denied-bool', 102, 'error', 'Permission denied');
        $this->handler->record_event('mcp.request', $tags, 3.1);

        $this->assertCount(1, $this->sink->records);
        $record = $this->sink->records[0]->toArray();

        $this->assertSame('denied', $record['decision']);
        $this->assertNull($record['result']); // decision records never carry a result.
        $this->assertTrue($record['input']['args']['_raw_args_unavailable']);
        $this->assertSame(2, $record['input']['args']['arguments_count']);
        $this->assertSame(['[REDACTED]', 'note'], $record['input']['args']['arguments_keys']);
    }

    /**
     * DOCUMENTS THE UPSTREAM GAP: a WP_Error-based permission denial carries no
     * error_code and an arbitrary custom message, INDISTINGUISHABLE by tags
     * alone from a WP_Error-based execution failure. Per the classification
     * rule (string-match ONLY the exact translated 'Permission denied'
     * default), this fixture does NOT match and is therefore filed as a
     * failed EXECUTION record, not a Denied decision -- even though the tool's
     * permission_callback is what actually rejected the call. This is the
     * single biggest "wall" this handler hits; see the class docblock and
     * UPSTREAM_ISSUE_audit_hook.md.
     */
    public function testPermissionDeniedWpErrorMessageIsMisclassifiedAsExecutionFailure(): void
    {
        $tags = self::commonTags('test-verify-denied-wp-error', 'test/verify-denied-wp-error', 103, 'error', 'Custom permission denial message');
        $this->handler->record_event('mcp.request', $tags, 2.0);

        $this->assertCount(1, $this->sink->records);
        $record = $this->sink->records[0]->toArray();

        $this->assertSame('allowed', $record['decision']); // NOT 'denied' -- the documented gap.
        $this->assertSame('error: Custom permission denial message', $record['result']);
    }

    public function testBlockedByPreToolCallFilterProducesFailedExecutionRecordWithRawArgs(): void
    {
        $rawArgs = ['api_key' => 'sekret', 'note' => 'blocked call'];
        McpRequestAuditHandler::captureArgs($rawArgs, 'test-verify-blocked');

        $tags = self::commonTags('test-verify-blocked', 'test/verify-blocked', 104, 'error', 'Rate limit exceeded for this session');
        $this->handler->record_event('mcp.request', $tags, 0.8);

        $this->assertCount(1, $this->sink->records);
        $record = $this->sink->records[0]->toArray();

        $this->assertSame('allowed', $record['decision']);
        $this->assertSame('error: Rate limit exceeded for this session', $record['result']);
        // Blocked happens AFTER mcp_adapter_pre_tool_call runs (our capture hook
        // fires at PHP_INT_MIN, before any subscriber can short-circuit), so raw
        // args ARE available here -- unlike the denied path above.
        $this->assertSame($rawArgs, $record['input']['args']);
    }

    public function testExecutionErrorProducesFailedExecutionRecordWithRawArgs(): void
    {
        $rawArgs = ['api_key' => 'sekret', 'note' => 'boom'];
        McpRequestAuditHandler::captureArgs($rawArgs, 'test-verify-execution-error');

        $tags = self::commonTags('test-verify-execution-error', 'test/verify-execution-error', 105, 'error', 'Custom execution failure message');
        $this->handler->record_event('mcp.request', $tags, 5.5);

        $record = $this->sink->records[0]->toArray();
        $this->assertSame('allowed', $record['decision']);
        $this->assertSame('error: Custom execution failure message', $record['result']);
        $this->assertSame($rawArgs, $record['input']['args']);
    }

    public function testExecutionExceptionProducesFailedExecutionRecordWithFullMessage(): void
    {
        $failureReason = 'Ability "test/verify-execution-exception" callback threw an exception: Boom from tool execute_callback';
        $tags = self::commonTags('test-verify-execution-exception', 'test/verify-execution-exception', 106, 'error', $failureReason);
        $this->handler->record_event('mcp.request', $tags, 1.2);

        $record = $this->sink->records[0]->toArray();
        $this->assertSame('allowed', $record['decision']);
        $this->assertSame('error: ' . $failureReason, $record['result']);
        $this->assertTrue($record['input']['args']['_raw_args_unavailable']); // never primed in this test.
    }

    public function testNonMcpRequestEventIsIgnored(): void
    {
        $tags = self::commonTags('test-verify-success', 'test/verify-success', 101, 'success');
        $this->handler->record_event('some.other.event', $tags, 1.0);

        $this->assertCount(0, $this->sink->records);
    }

    public function testNonToolComponentTypeIsIgnored(): void
    {
        $tags = self::commonTags('test-verify-success', 'test/verify-success', 101, 'success');
        $tags['component_type'] = 'prompt';
        $this->handler->record_event('mcp.request', $tags, 1.0);

        $this->assertCount(0, $this->sink->records);
    }

    public function testNonToolsCallMethodIsIgnored(): void
    {
        $tags = self::commonTags('test-verify-success', 'test/verify-success', 101, 'success');
        $tags['method'] = 'resources/read';
        $this->handler->record_event('mcp.request', $tags, 1.0);

        $this->assertCount(0, $this->sink->records);
    }

    public function testUserIdIsFetchedFreshInsideRecordEvent(): void
    {
        $GLOBALS['wpas_test_current_user_id'] = 7;
        $tags1 = self::commonTags('test-verify-success', 'test/verify-success', 201, 'success');
        $this->handler->record_event('mcp.request', $tags1);

        // Change the "logged in user" BETWEEN two events in the same test run
        // (impossible if the actor were memoized anywhere): proves wp_user is
        // resolved fresh, synchronously, on each record_event() call.
        $GLOBALS['wpas_test_current_user_id'] = 99;
        $tags2 = self::commonTags('test-verify-success', 'test/verify-success', 202, 'success');
        $this->handler->record_event('mcp.request', $tags2);

        $this->assertSame(7, $this->sink->records[0]->toArray()['actor']['wp_user']);
        $this->assertSame(99, $this->sink->records[1]->toArray()['actor']['wp_user']);
    }

    public function testStashIsConsumedFifoPerToolThenExhausted(): void
    {
        McpRequestAuditHandler::captureArgs(['call' => 'first'], 'batch-tool');
        McpRequestAuditHandler::captureArgs(['call' => 'second'], 'batch-tool');

        $tags = self::commonTags('batch-tool', 'test/batch-tool', 301, 'success');

        $this->handler->record_event('mcp.request', $tags, null);
        $this->handler->record_event('mcp.request', $tags, null);
        // Third call: stash for 'batch-tool' is now empty -- no priming happened
        // for this one, so it must fall back to the no-raw-args marker, exactly
        // like the denied-path tests above.
        $this->handler->record_event('mcp.request', $tags, null);

        $this->assertSame(['call' => 'first'], $this->sink->records[0]->toArray()['input']['args']);
        $this->assertSame(['call' => 'second'], $this->sink->records[1]->toArray()['input']['args']);
        $this->assertTrue($this->sink->records[2]->toArray()['input']['args']['_raw_args_unavailable']);
    }
}
