<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Plugin\Admin\PendingActionsPage;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeApprovalStore;

/**
 * AS-11: the approval summary filter + the wp_kses rendering contract.
 *
 * The filter lets a host enrich the human-facing summary (SenroFlux's rich
 * publish rows); the Pending Actions screen then renders that summary through
 * wp_kses with exactly one allowed element (`<a href>`), so a filter can add a
 * link but never script or markup. The filter fires once per persisted row,
 * before ApprovalStore::request(), and can never touch the binding.
 */
final class ApprovalSummaryTest extends TestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('agent_safety_approval_summary');
        RequestContext::reset();
    }

    public function testFilterOverridesThePersistedSummaryOncePerRow(): void
    {
        $store = new FakeApprovalStore();
        $store->nextId = 'apr_rich';
        $recorder = new DecisionRecorder(null, $store);
        $input = ['id' => 42];

        $calls = [];
        add_filter('agent_safety_approval_summary', static function ($summary, $verb, $in) use (&$calls) {
            $calls[] = ['summary' => $summary, 'verb' => $verb, 'input' => $in];

            return 'Publish "Pricing" — <a href="/preview">preview</a>';
        }, 10, 3);

        $id = $recorder->requestApproval('pages/publish', $input, 'evt_1');

        $this->assertSame('apr_rich', $id);
        $this->assertCount(1, $calls, 'the filter fires exactly once per request row');
        $this->assertSame('pages/publish { id=42 }', $calls[0]['summary'], 'the filter receives the flat summary');
        $this->assertSame('pages/publish', $calls[0]['verb']);
        $this->assertSame(42, $calls[0]['input']['id']);

        // The ENRICHED summary is what the store persists.
        $this->assertCount(1, $store->requestCalls);
        $this->assertSame(
            'Publish "Pricing" — <a href="/preview">preview</a>',
            $store->requestCalls[0]['summary']
        );

        // The binding is untouched by the filter: same verb + same args hash.
        $this->assertSame('pages/publish', $store->requestCalls[0]['verb']);
        $this->assertSame(ApprovalBinding::hash('pages/publish', $input), $store->requestCalls[0]['args_hash']);
    }

    public function testUnhookedFilterKeepsTheFlatSummary(): void
    {
        $store = new FakeApprovalStore();
        $recorder = new DecisionRecorder(null, $store);

        $recorder->requestApproval('orders/refund', ['id' => 1], 'evt_1');

        $this->assertSame('orders/refund { id=1 }', $store->requestCalls[0]['summary']);
    }

    public function testANonStringFilterReturnFallsBackToTheFlatSummary(): void
    {
        $store = new FakeApprovalStore();
        $recorder = new DecisionRecorder(null, $store);
        add_filter('agent_safety_approval_summary', static fn () => ['not' => 'a string'], 10, 3);

        $recorder->requestApproval('orders/refund', ['id' => 1], 'evt_1');

        $this->assertSame('orders/refund { id=1 }', $store->requestCalls[0]['summary']);
    }

    public function testSummaryHtmlAllowsOnlyAnchorHref(): void
    {
        $rich = 'Publish "Pricing" — <a href="/preview" onclick="evil()">preview</a>'
            . '<script>alert(1)</script><strong>bold</strong>';

        $html = PendingActionsPage::summaryHtml($rich);

        // Every non-allowed TAG goes (wp_kses strips tags, never text nodes):
        // the anchor survives with ONLY its href; script/strong elements and
        // the inline handler go, so nothing is executable or styled by a host.
        $this->assertSame('Publish "Pricing" — <a href="/preview">preview</a>alert(1)bold', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('</script', $html);
        $this->assertStringNotContainsString('<strong', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function testSummaryHtmlLeavesPlainTextUntouched(): void
    {
        $this->assertSame('orders/refund { id=1 }', PendingActionsPage::summaryHtml('orders/refund { id=1 }'));
    }
}
