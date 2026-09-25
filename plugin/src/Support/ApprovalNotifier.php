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

    /**
     * Where notifications go: the configured recipient, else the site's
     * admin_email; null when neither is set, in which case nothing is sent.
     */
    public function recipient(): ?string
    {
        $to = (string) get_option(self::EMAIL_OPTION, '');
        if ($to === '') {
            $to = (string) get_option('admin_email', '');
        }

        return $to === '' ? null : $to;
    }

    /**
     * An operator alert that is not about an approval — a tripwire locking a
     * credential out ({@see Tripwires}) — over the same mail path and to the
     * same recipient, without the approval-specific recipient filter. The
     * caller composes the body from identifiers it resolved itself, never
     * from anything the agent supplied.
     */
    public function alert(string $subject, string $body): void
    {
        $to = $this->recipient();
        if ($to === null) {
            return;
        }

        wp_mail($to, '[Agent Safety] ' . $subject, $body);
    }

    private function email(string $approvalId, string $verb, string $summary): void
    {
        $to = apply_filters('agent_safety_approval_notify_to', $this->recipient() ?? '', $approvalId, $verb);
        if (!is_string($to) || $to === '') {
            return;
        }

        wp_mail(
            $to,
            sprintf(
                /* translators: %s the verb (ability id) the agent tried to call */
                __('[Agent Safety] Approval requested: %s', 'agent-safety'),
                $verb
            ),
            sprintf(
                /* translators: 1: action summary, 2: approval id, 3: review URL */
                __(
                    "An agent action is awaiting human approval.\n\n"
                    . "Action: %1\$s\nApproval id: %2\$s\n\n"
                    . "Review, then approve or reject (requires login):\n%3\$s\n",
                    'agent-safety'
                ),
                SummaryMarkup::unwrap($summary),
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
