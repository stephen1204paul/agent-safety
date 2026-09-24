<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Privacy;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Privacy\PrivacyPolicyContent;

/**
 * §3.6 item 2: the suggested privacy-policy text names what's recorded, why
 * it survives an erasure request, and the opt-in notification surfaces.
 */
final class PrivacyPolicyContentTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_privacy_policy_content'] = [];
    }

    public function testAddsSuggestedTextCoveringRetentionAndInputs(): void
    {
        (new PrivacyPolicyContent())->add();

        $this->assertCount(1, $GLOBALS['wpas_test_privacy_policy_content']);
        $entry = $GLOBALS['wpas_test_privacy_policy_content'][0];

        $this->assertSame('Agent Safety', $entry['plugin_name']);
        $this->assertStringContainsString('tool', $entry['content']);
        $this->assertStringContainsString('customer data', $entry['content']);
        $this->assertStringContainsString('chained', $entry['content']);
        $this->assertStringContainsString('webhook', $entry['content']);
    }
}
