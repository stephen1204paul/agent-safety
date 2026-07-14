<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Routes a NEW pending approval to the humans who must clear it (roadmap 0.2
 * "approval notifications"): an email, and optionally a webhook for Slack or
 * anything else. Subscribes to `agent_safety_approval_requested`, which
 * {@see \Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore::request()}
 * fires only on the fresh-insert path — a retrying agent reusing its pending
 * row never re-notifies. Without this, the realistic failure mode is admins
 * loosening packs to avoid approval friction; the flow has to be fast enough
 * to keep.
 *
 * The email links to the login-protected review screen rather than carrying
 * one-click approve/reject links, deliberately: an unauthenticated link that
 * approves an IRREVERSIBLE action is exactly the kind of ambient authority
 * this plugin exists to remove (a forwarded email must not be a grant), and
 * approver attribution in the audit chain needs a logged-in user.
 *
 * The webhook payload carries identifiers and the review URL, never the call
 * summary or arguments: the summary can contain raw argument values
 * (customer emails, order details), and shipping PII to an external endpoint
 * must be an explicit site decision — widen it via the
 * `agent_safety_webhook_payload` filter if your endpoint is trusted. The
 * email DOES include the summary; it goes to the same administrators the
 * review screen shows it to.
 */
final class ApprovalNotifier
{
    /** Option: notification recipient; empty = the site's admin_email. */
    public const EMAIL_OPTION = 'agsafe_notify_email';

    /** Option: webhook endpoint URL; empty = webhook disabled. */
    public const WEBHOOK_OPTION = 'agsafe_webhook_url';

    public function register(): void
    {
        add_action('agent_safety_approval_requested', [$this, 'notify'], 10, 3);
    }

    public function notify(string $approvalId, string $verb, string $summary): void
    {
        $this->email($approvalId, $verb, $summary);
        $this->webhook($approvalId, $verb);
    }

    private function email(string $approvalId, string $verb, string $summary): void
    {
        $to = (string) get_option(self::EMAIL_OPTION, '');
        if ($to === '') {
            $to = (string) get_option('admin_email', '');
        }
        $to = apply_filters('agent_safety_approval_notify_to', $to, $approvalId, $verb);
        if (!is_string($to) || $to === '') {
            return;
        }

        wp_mail(
            $to,
            sprintf('[Agent Safety] Approval requested: %s', $verb),
            sprintf(
                "An agent action is awaiting human approval.\n\n"
                . "Action: %s\nApproval id: %s\n\n"
                . "Review, then approve or reject (requires login):\n%s\n",
                $summary,
                $approvalId,
                $this->reviewUrl(),
            ),
        );
    }

    private function webhook(string $approvalId, string $verb): void
    {
        $url = apply_filters('agent_safety_webhook_url', (string) get_option(self::WEBHOOK_OPTION, ''));
        if (!is_string($url) || $url === '') {
            return;
        }

        $payload = apply_filters('agent_safety_webhook_payload', [
            'event' => 'approval.requested',
            'approval_id' => $approvalId,
            'verb' => $verb,
            'review_url' => $this->reviewUrl(),
        ], $approvalId, $verb);

        $body = wp_json_encode($payload);
        if (!is_string($body)) {
            return;
        }

        wp_remote_post($url, [
            'timeout' => 3.0,
            'blocking' => false,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => $body,
        ]);
    }

    private function reviewUrl(): string
    {
        return admin_url('tools.php?page=agent-safety-pending');
    }
}
