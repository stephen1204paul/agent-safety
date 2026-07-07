<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Hooks\ToolCallResultRedactor;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeMcpTool;
use WP_Error;

/**
 * Exercises {@see ToolCallResultRedactor} — read-path PII redaction (backlog
 * #11) on the `mcp_adapter_tool_call_result` filter. `default-agent` (the
 * builtin fallback pack a resolver with no bindings/tokens returns) redacts
 * PII by default (Pack's own `pii` default is 'redacted'); `owner` ships
 * `pii: 'full'`, so binding to it is how these tests get a non-redacting pack.
 */
final class ToolCallResultRedactorTest extends TestCase
{
    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
    }

    private function redactorFor(array $governedNamespaces = ['woocommerce/']): ToolCallResultRedactor
    {
        return new ToolCallResultRedactor(new PackResolver(), $governedNamespaces);
    }

    /** Bind the current request's token to the given builtin pack name. */
    private function bindTo(string $packName): void
    {
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['test:token']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = ['test:token' => $packName];
    }

    public function testArrayResultIsRedactedWhenTheResolvedPackRedactsPii(): void
    {
        $redactor = $this->redactorFor();
        $result = ['email' => 'agent@example.com', 'note' => 'hi'];

        $redacted = $redactor->redact($result, [], 'woocommerce-orders-list', FakeMcpTool::withAbility('woocommerce/orders-list'));

        $this->assertSame('«redacted»', $redacted['email']);
        $this->assertSame('hi', $redacted['note']);
    }

    public function testNestedArrayResultIsRedactedRecursively(): void
    {
        $redactor = $this->redactorFor();
        $result = ['data' => ['customer' => ['email' => 'agent@example.com', 'id' => 7]]];

        $redacted = $redactor->redact($result, [], 'woocommerce-orders-list', FakeMcpTool::withAbility('woocommerce/orders-list'));

        $this->assertSame('«redacted»', $redacted['data']['customer']['email']);
        $this->assertSame(7, $redacted['data']['customer']['id']);
    }

    public function testArrayResultIsUntouchedWhenTheResolvedPackDoesNotRedactPii(): void
    {
        $this->bindTo('owner');
        $redactor = $this->redactorFor();
        $result = ['email' => 'agent@example.com'];

        $redacted = $redactor->redact($result, [], 'woocommerce-orders-list', FakeMcpTool::withAbility('woocommerce/orders-list'));

        $this->assertSame($result, $redacted);
    }

    public function testWpErrorPassesThroughIdenticalAndUntouched(): void
    {
        $redactor = $this->redactorFor();
        $error = new WP_Error('some_error', 'boom');

        $result = $redactor->redact($error, [], 'woocommerce-orders-list', FakeMcpTool::withAbility('woocommerce/orders-list'));

        $this->assertSame($error, $result);
        $this->assertSame('boom', $result->get_error_message());
    }

    public function testScalarResultPassesThroughUntouched(): void
    {
        $redactor = $this->redactorFor();

        $this->assertSame('plain-text-result', $redactor->redact('plain-text-result', [], 'woocommerce-orders-list', FakeMcpTool::withAbility('woocommerce/orders-list')));
        $this->assertSame(42, $redactor->redact(42, [], 'woocommerce-orders-list', FakeMcpTool::withAbility('woocommerce/orders-list')));
    }

    public function testUngovernedNamespaceIsUntouchedEvenWhenThePackWouldRedact(): void
    {
        $redactor = $this->redactorFor(['woocommerce/']);
        $result = ['email' => 'agent@example.com'];

        $redacted = $redactor->redact($result, [], 'core-something', FakeMcpTool::withAbility('core/something'));

        $this->assertSame($result, $redacted);
    }

    public function testEmptyGovernedNamespacesRedactsNothing(): void
    {
        $redactor = $this->redactorFor([]);
        $result = ['email' => 'agent@example.com'];

        $redacted = $redactor->redact($result, [], 'woocommerce-orders-list', FakeMcpTool::withAbility('woocommerce/orders-list'));

        $this->assertSame($result, $redacted);
    }

    public function testFallsBackToTheRawToolNameWhenTheMcpToolHasNoObservabilityContext(): void
    {
        // No $mcp_tool argument at all: falls back to $toolName, which does not
        // start with a governed prefix -> untouched, the same fail-safe default
        // as an ungoverned ability name.
        $redactor = $this->redactorFor(['woocommerce/']);
        $result = ['email' => 'agent@example.com'];

        $redacted = $redactor->redact($result, [], 'woocommerce-orders-list');

        $this->assertSame($result, $redacted);
    }

    public function testForeignMcpToolObjectNeverFatalsAndFallsBackToToolName(): void
    {
        $redactor = $this->redactorFor(['woocommerce/']);
        $result = ['email' => 'agent@example.com'];

        $redacted = $redactor->redact($result, [], 'woocommerce-orders-list', new \stdClass());

        $this->assertSame($result, $redacted);
    }
}
