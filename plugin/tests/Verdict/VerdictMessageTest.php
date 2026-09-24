<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Verdict\ApprovalMessageVariant;
use Specflux\AgentSafety\Plugin\Verdict\Verdict;
use Specflux\AgentSafety\Policy\Tier;

/**
 * AS-6 §3.11: {@see Verdict::error()}'s `approval_required` message text, by
 * {@see ApprovalMessageVariant}. The frozen CODE and DATA shape are pinned
 * separately by {@see VerdictErrorContractTest} — this file only pins the
 * human-readable wording, which is exactly what AS-6 changed.
 */
final class VerdictMessageTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['wpas_test_translations']);
    }

    public function testBaseVariantIsTheExactTierNeutralMessage(): void
    {
        $verdict = new Verdict(
            'woocommerce/orders-update',
            new Pack(name: 'support', allow: ['woocommerce/*']),
            Decision::approvalRequired(Tier::Irreversible),
            'apr_0123456789abcdef',
        );

        $error = $verdict->error();

        $this->assertNotNull($error);
        $this->assertSame(
            '"woocommerce/orders-update" needs human approval before it can run. A request has been filed for review.',
            $error->get_error_message(),
        );
        $this->assertStringNotContainsString('irreversible', $error->get_error_message(), 'the old blanket wording must be gone');
    }

    public function testStaleVariantIsTheExactChangedTargetMessage(): void
    {
        $verdict = new Verdict(
            'woocommerce/product-update',
            new Pack(name: 'support', allow: ['woocommerce/*']),
            Decision::approvalRequired(Tier::Irreversible),
            'apr_fresh',
            approvalVariant: ApprovalMessageVariant::Stale,
        );

        $error = $verdict->error();

        $this->assertNotNull($error);
        $this->assertSame(
            '"woocommerce/product-update" needs human approval again: the target changed after the earlier approval, so a new request has been filed for review.',
            $error->get_error_message(),
        );
    }

    /**
     * Proves the message is actually routed through __('...', 'agent-safety')
     * rather than being a plain sprintf() literal, the same way
     * McpRequestAuditHandlerTest proves upstream's translation routing.
     */
    public function testBaseMessageIsRoutedThroughTheAgentSafetyTextDomain(): void
    {
        $GLOBALS['wpas_test_translations']['agent-safety']['"%1$s" needs human approval before it can run. A request has been filed for review.']
            = '„%1$s“ braucht erneut eine menschliche Freigabe.';

        $verdict = new Verdict(
            'woocommerce/orders-update',
            new Pack(name: 'support', allow: ['woocommerce/*']),
            Decision::approvalRequired(Tier::Irreversible),
        );

        $error = $verdict->error();

        $this->assertNotNull($error);
        $this->assertSame('„woocommerce/orders-update“ braucht erneut eine menschliche Freigabe.', $error->get_error_message());
    }
}
