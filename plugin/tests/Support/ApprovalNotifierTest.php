<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\ApprovalNotifier;

final class ApprovalNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
        $GLOBALS['wpas_test_mail'] = [];
        $GLOBALS['wpas_test_http'] = [];
    }

    public function testEmailsTheSiteAdminByDefaultWithSummaryAndReviewLink(): void
    {
        $GLOBALS['wpas_test_options']['admin_email'] = 'owner@example.test';

        (new ApprovalNotifier())->notify('apr_1', 'woocommerce/orders-refund', 'orders-refund { amount=50 }');

        $this->assertCount(1, $GLOBALS['wpas_test_mail']);
        $mail = $GLOBALS['wpas_test_mail'][0];
        $this->assertSame('owner@example.test', $mail['to']);
        $this->assertStringContainsString('woocommerce/orders-refund', $mail['subject']);
        $this->assertStringContainsString('orders-refund { amount=50 }', $mail['message']);
        $this->assertStringContainsString('apr_1', $mail['message']);
        $this->assertStringContainsString('tools.php?page=agent-safety-pending', $mail['message']);
    }

    public function testConfiguredRecipientOptionBeatsTheAdminEmailDefault(): void
    {
        $GLOBALS['wpas_test_options']['admin_email'] = 'owner@example.test';
        $GLOBALS['wpas_test_options'][ApprovalNotifier::EMAIL_OPTION] = 'security@example.test';

        (new ApprovalNotifier())->notify('apr_1', 'woocommerce/orders-refund', 'summary');

        $this->assertSame('security@example.test', $GLOBALS['wpas_test_mail'][0]['to']);
    }

    public function testSendsNoEmailWhenNoRecipientResolves(): void
    {
        (new ApprovalNotifier())->notify('apr_1', 'woocommerce/orders-refund', 'summary');

        $this->assertSame([], $GLOBALS['wpas_test_mail']);
    }

    public function testSendsNoWebhookWhenNoUrlIsConfigured(): void
    {
        $GLOBALS['wpas_test_options']['admin_email'] = 'owner@example.test';

        (new ApprovalNotifier())->notify('apr_1', 'woocommerce/orders-refund', 'summary');

        $this->assertSame([], $GLOBALS['wpas_test_http']);
    }

    public function testWebhookPostsIdentifiersOnlyJsonNeverTheSummary(): void
    {
        $GLOBALS['wpas_test_options'][ApprovalNotifier::WEBHOOK_OPTION] = 'https://hooks.example.test/agsafe';

        (new ApprovalNotifier())->notify('apr_9', 'woocommerce/orders-refund', 'orders-refund { email=pii@example.test }');

        $this->assertCount(1, $GLOBALS['wpas_test_http']);
        $post = $GLOBALS['wpas_test_http'][0];
        $this->assertSame('https://hooks.example.test/agsafe', $post['url']);
        $this->assertFalse($post['args']['blocking']);
        $this->assertSame('application/json', $post['args']['headers']['Content-Type']);

        $payload = json_decode((string) $post['args']['body'], true);
        $this->assertSame('approval.requested', $payload['event']);
        $this->assertSame('apr_9', $payload['approval_id']);
        $this->assertSame('woocommerce/orders-refund', $payload['verb']);
        $this->assertStringContainsString('tools.php?page=agent-safety-pending', $payload['review_url']);
        // The summary carries raw argument values; it must never leave the site.
        $this->assertStringNotContainsString('pii@example.test', (string) $post['args']['body']);
    }
}
