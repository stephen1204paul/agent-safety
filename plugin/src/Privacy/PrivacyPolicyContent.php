<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Privacy;

/**
 * Suggested privacy-policy text (§3.6 item 2), added via
 * `wp_add_privacy_policy_content()` on `admin_init`, per the WP core
 * privacy-tools convention (Settings → Privacy → "Copy suggested policy
 * text"). Covers what's recorded, why it survives an erasure request, and the
 * opt-in notification surfaces briefly (§3.6 item 5).
 */
final class PrivacyPolicyContent
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'add']);
    }

    public function add(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = '<p class="privacy-policy-tutorial">' . __(
            "Agent Safety records every governed AI-agent tool call in a tamper-evident audit log: which WordPress user or application password acted, their IP address, and the tool's input arguments — which may include customer data handled by other plugins (for example, an order or a customer record passed as a tool argument).",
            'agent-safety'
        ) . '</p><p>' . __(
            "These records are kept even after a data-erasure request: each entry is cryptographically chained to the one before it, so rewriting or deleting an entry would break that chain for every later entry and defeat the tamper-evidence the log exists to provide. A personal-data export includes a user's own audit records; a personal-data erasure request reports them as retained instead of removing them.",
            'agent-safety'
        ) . '</p><p>' . __(
            'If the site administrator has opted in to notifications for pending approvals, an outgoing webhook or admin email may carry the approval id, the ability name, its tier, the requester and the site URL. Both are off by default.',
            'agent-safety'
        ) . '</p>';

        wp_add_privacy_policy_content(__('Agent Safety', 'agent-safety'), $content);
    }
}
