<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Admin;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Plugin\Admin\PendingActionsPage;
use Specflux\AgentSafety\Plugin\Api\Approvals;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\SummaryMarkup;
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
        $GLOBALS['wpas_test_user_caps'] = [];
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

        // The ENRICHED summary is what the store persists, tagged host-authored
        // so the approval screen may render it as markup.
        $this->assertCount(1, $store->requestCalls);
        $this->assertSame(
            SummaryMarkup::wrap('Publish "Pricing" — <a href="/preview">preview</a>'),
            $store->requestCalls[0]['summary']
        );
        $this->assertTrue(SummaryMarkup::isHostAuthored($store->requestCalls[0]['summary']));

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

    public function testFilterReturningTheFlatSummaryUnchangedStaysAgentAuthored(): void
    {
        $store = new FakeApprovalStore();
        $recorder = new DecisionRecorder(null, $store);
        add_filter('agent_safety_approval_summary', static fn ($summary) => $summary, 10, 3);

        $recorder->requestApproval('orders/refund', ['id' => 1], 'evt_1');

        // Nothing was enriched, so nothing earns markup rendering.
        $this->assertSame('orders/refund { id=1 }', $store->requestCalls[0]['summary']);
        $this->assertFalse(SummaryMarkup::isHostAuthored($store->requestCalls[0]['summary']));
    }

    public function testSummaryHtmlAllowsOnlyAnchorHrefForHostAuthoredSummaries(): void
    {
        $rich = SummaryMarkup::wrap(
            'Publish "Pricing" — <a href="/preview" onclick="evil()">preview</a>'
            . '<script>alert(1)</script><strong>bold</strong>'
        );

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

    public function testSummaryHtmlEscapesAgentAuthoredMarkup(): void
    {
        // The regression: a summary the agent controls must never reach wp_kses,
        // or an argument value can plant a LIVE link in the approval queue.
        $planted = 'pages/publish { title=<a href="https://evil.test">click</a><script>alert(1)</script> }';

        $html = PendingActionsPage::summaryHtml($planted);

        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('&lt;a href=&quot;https://evil.test&quot;&gt;', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function testSummaryHtmlDropsABadProtocolHrefEvenWhenHostAuthored(): void
    {
        $html = PendingActionsPage::summaryHtml(
            SummaryMarkup::wrap('Review <a href="javascript:alert(1)">this</a>')
        );

        // Asserted as a property, not as an exact string: the shim DROPS the
        // attribute where core's wp_kses_bad_protocol() strips just the scheme.
        // Both satisfy "no live javascript: link"; only that is contracted here.
        $this->assertStringNotContainsString('javascript', $html);
        $this->assertStringNotContainsString('href="javascript', $html);
        $this->assertStringContainsString('this</a>', $html);
    }

    public function testSummaryHtmlLeavesPlainTextUntouched(): void
    {
        $this->assertSame('orders/refund { id=1 }', PendingActionsPage::summaryHtml('orders/refund { id=1 }'));
    }

    /**
     * The helper being correct is not enough: render() has to actually route the
     * Summary cell through it. Records a row the way production does (agent
     * arguments carrying markup), then renders the real screen over that row.
     */
    public function testRenderEscapesAnAgentPlantedLinkInTheSummaryCell(): void
    {
        $fake = new FakeApprovalStore();
        (new DecisionRecorder(null, $fake))->requestApproval(
            'pages/publish',
            ['title' => '<a href="javascript:alert(1)">gift card</a><script>alert(1)</script>'],
            'evt_1'
        );

        $out = $this->renderWithSummary($fake->requestCalls[0]['summary']);

        $this->assertStringNotContainsString('<a href="javascript:alert(1)"', $out);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $out);
        $this->assertStringContainsString('&lt;a href=&quot;javascript:alert(1)&quot;&gt;gift card&lt;/a&gt;', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    public function testRenderKeepsAHostEnrichedLinkInTheSummaryCell(): void
    {
        add_filter(
            'agent_safety_approval_summary',
            static fn () => 'Publish "Pricing" — <a href="/preview" onclick="evil()">preview</a>',
            10,
            3
        );
        $fake = new FakeApprovalStore();
        (new DecisionRecorder(null, $fake))->requestApproval('pages/publish', ['id' => 42], 'evt_1');

        $out = $this->renderWithSummary($fake->requestCalls[0]['summary']);

        $this->assertStringContainsString('<td>Publish "Pricing" — <a href="/preview">preview</a></td>', $out);
        $this->assertStringNotContainsString('onclick', $out);
    }

    /** Render the real Pending Actions screen over a single row with this summary. */
    private function renderWithSummary(string $summary): string
    {
        $db = new \wpdb();
        $db->resultsReturn = [[
            'approval_id' => 'apr_1',
            'verb' => 'pages/publish',
            'summary' => $summary,
            'correlation_id' => 'corr_1',
            'created_ts' => '2026-01-01 00:00:00',
            'pending_expires_ts' => '2026-01-01 01:00:00',
            'status' => 'pending',
        ]];
        $store = new WpdbApprovalStore($db);
        $page = new PendingActionsPage($store, new Approvals($store));
        $GLOBALS['wpas_test_user_caps']['manage_options'] = true;

        ob_start();
        $page->render();

        return (string) ob_get_clean();
    }
}
