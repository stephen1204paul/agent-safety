<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Support\ApprovalNotifier;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;

/**
 * Tools → "Pending Agent Actions": the human side of the approval flow.
 * Lists irreversible verbs an agent tried to run that were blocked pending review.
 * Approving mints a single-use, verb+args-bound token (shown ONCE) and appends an
 * `approved` event to the audit chain; rejecting appends a `rejected` event.
 *
 * Reconciliation is append-only: the audit log is hash-chained and immutable, so a
 * verdict change is a NEW linked row referencing the same approval id — never a
 * mutation of the original `pending` row.
 */
final class PendingActionsPage
{
    private const SLUG = 'agent-safety-pending';
    private const CAP = 'manage_options';
    private const APPROVE = 'agsafe_approve_action';
    private const REJECT = 'agsafe_reject_action';
    private const NOTIFY = 'agsafe_save_notifications';
    private const FLASH = 'agsafe_minted_token_';

    public function __construct(
        private readonly WpdbApprovalStore $store,
        private readonly ?AuditSink $sink = null,
        private readonly ?PackResolver $packs = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::APPROVE, [$this, 'approve']);
        add_action('admin_post_' . self::REJECT, [$this, 'reject']);
        add_action('admin_post_' . self::NOTIFY, [$this, 'saveNotifications']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Pending Agent Actions', 'agent-safety'),
            __('Pending Agent Actions', 'agent-safety'),
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAP)) {
            return;
        }

        $rows = $this->store->pending();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Pending Agent Actions', 'agent-safety') . '</h1>';
        echo '<p>' . esc_html__('Irreversible agent actions blocked pending human approval. Approving mints a single-use token bound to the exact verb + arguments.', 'agent-safety') . '</p>';

        $this->maybeShowMintedToken();

        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Requested (UTC)', 'Expires (UTC)', 'Correlation', 'Verb', 'Summary', 'Approval ID', 'Action'] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="7">' . esc_html__('No pending actions. The agent has nothing awaiting review.', 'agent-safety') . '</td></tr>';
        }

        foreach ($rows as $r) {
            $approvalId = (string) ($r['approval_id'] ?? '');
            echo '<tr>';
            echo '<td>' . esc_html((string) ($r['created_ts'] ?? '')) . '</td>';
            echo '<td>' . esc_html((string) ($r['pending_expires_ts'] ?? '')) . '</td>';
            echo '<td><code>' . esc_html((string) ($r['correlation_id'] ?? '')) . '</code></td>';
            echo '<td><code>' . esc_html((string) ($r['verb'] ?? '')) . '</code></td>';
            echo '<td>' . esc_html((string) ($r['summary'] ?? '')) . '</td>';
            echo '<td><code style="font-size:11px;">' . esc_html($approvalId) . '</code></td>';
            echo '<td>' . $this->actionButtons($approvalId) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_* helpers below.
            echo '</tr>';
        }

        echo '</tbody></table>';
        $this->renderNotificationSettings();
        echo '</div>';
    }

    /**
     * Where new pending actions get routed: an email recipient (empty = the
     * site's admin_email) and an optional webhook URL. Lives on this screen
     * because the settings only matter to whoever clears this queue.
     */
    private function renderNotificationSettings(): void
    {
        echo '<h2>' . esc_html__('Notifications', 'agent-safety') . '</h2>';
        echo '<p>' . esc_html__('Each NEW pending action sends an email (with a link to this screen) and, if a webhook URL is set, an identifiers-only JSON POST — no call arguments leave the site.', 'agent-safety') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::NOTIFY) . '">';
        echo wp_nonce_field(self::NOTIFY, '_wpnonce', true, false); // phpcs:ignore WordPress.Security.EscapeOutput -- core-built hidden fields.
        echo '<table class="form-table"><tbody>';
        printf(
            '<tr><th scope="row"><label for="agsafe_notify_email">%s</label></th>'
            . '<td><input type="email" id="agsafe_notify_email" name="agsafe_notify_email" class="regular-text" value="%s" placeholder="%s"></td></tr>',
            esc_html__('Notification email', 'agent-safety'),
            esc_attr((string) get_option(ApprovalNotifier::EMAIL_OPTION, '')),
            esc_attr__('Site admin email (default)', 'agent-safety'),
        );
        printf(
            '<tr><th scope="row"><label for="agsafe_webhook_url">%s</label></th>'
            . '<td><input type="url" id="agsafe_webhook_url" name="agsafe_webhook_url" class="regular-text" value="%s" placeholder="https://"></td></tr>',
            esc_html__('Webhook URL', 'agent-safety'),
            esc_attr((string) get_option(ApprovalNotifier::WEBHOOK_OPTION, '')),
        );
        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save notification settings', 'agent-safety') . '</button></p>';
        echo '</form>';
    }

    public function saveNotifications(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'agent-safety'));
        }
        check_admin_referer(self::NOTIFY);

        $email = isset($_POST['agsafe_notify_email'])
            ? sanitize_email(wp_unslash($_POST['agsafe_notify_email']))
            : '';
        $webhook = isset($_POST['agsafe_webhook_url'])
            ? esc_url_raw(wp_unslash($_POST['agsafe_webhook_url']), ['https', 'http'])
            : '';

        update_option(ApprovalNotifier::EMAIL_OPTION, $email);
        update_option(ApprovalNotifier::WEBHOOK_OPTION, $webhook);

        $this->redirectBack();
    }

    public function approve(): void
    {
        $approvalId = $this->guard(self::APPROVE);
        $approver = get_current_user_id();

        $token = $this->store->approve($approvalId, $approver);
        if ($token !== null) {
            $this->reconcile($approvalId, AuditDecision::Approved, $approver);
            set_transient(self::FLASH . $approver, ['approval_id' => $approvalId, 'token' => $token], 120);
        }

        $this->redirectBack();
    }

    public function reject(): void
    {
        $approvalId = $this->guard(self::REJECT);
        $approver = get_current_user_id();

        if ($this->store->reject($approvalId, $approver)) {
            $this->reconcile($approvalId, AuditDecision::Rejected, $approver);
        }

        $this->redirectBack();
    }

    /**
     * Append an `approved`/`rejected` event tied to the original request — the
     * chain-safe reconciliation of the earlier `pending` row.
     */
    private function reconcile(string $approvalId, AuditDecision $decision, int $approver): void
    {
        if ($this->sink === null) {
            return;
        }

        $record = $this->store->get($approvalId);
        if ($record === null) {
            return;
        }

        $pack = $this->packs?->resolve()->name ?? 'default-agent';

        $this->sink->append(AuditRecord::decision(
            id: RequestContext::event(),
            ts: RequestContext::nowUtc(),
            correlationId: (string) ($record['correlation_id'] ?? ''),
            pack: $pack,
            actor: RequestContext::actor(),
            ability: (string) ($record['verb'] ?? ''),
            tier: null,
            input: ['args_hash' => (string) ($record['args_hash'] ?? '')],
            decision: $decision,
            approval: ['id' => $approvalId, 'approver' => $approver],
            ip: RequestContext::ip(),
        ));
    }

    private function maybeShowMintedToken(): void
    {
        $user = get_current_user_id();
        $flash = get_transient(self::FLASH . $user);
        if (!is_array($flash) || empty($flash['token'])) {
            return;
        }
        delete_transient(self::FLASH . $user);

        printf(
            '<div class="notice notice-success"><p><strong>%s</strong></p><p>%s</p><p><code style="font-size:13px;user-select:all;">%s</code></p><p>%s</p></div>',
            esc_html__('Approved.', 'agent-safety'),
            esc_html(sprintf(/* translators: %s approval id */ __('Approval %s is now granted. The same agent (same API key) can simply re-issue the exact same call and it will run — no token needed. To delegate the action to a different actor, hand them the single-use token below as the _approval argument instead.', 'agent-safety'), (string) $flash['approval_id'])),
            esc_html((string) $flash['token']),
            esc_html__('Valid once, for 15 minutes, bound to that exact verb + arguments. Shown only now and never stored in the clear.', 'agent-safety')
        );
    }

    private function actionButtons(string $approvalId): string
    {
        $out = '';
        foreach ([self::APPROVE => ['Approve', 'primary'], self::REJECT => ['Reject', 'secondary']] as $action => [$label, $style]) {
            $out .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:6px;">';
            $out .= '<input type="hidden" name="action" value="' . esc_attr($action) . '">';
            $out .= '<input type="hidden" name="approval_id" value="' . esc_attr($approvalId) . '">';
            $out .= wp_nonce_field($action . $approvalId, '_wpnonce', true, false);
            $out .= '<button type="submit" class="button button-' . esc_attr($style) . '">' . esc_html__($label, 'agent-safety') . '</button>';
            $out .= '</form>';
        }

        return $out;
    }

    /** Verify cap + nonce, return the sanitised approval id, or wp_die. */
    private function guard(string $action): string
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'agent-safety'));
        }
        $approvalId = isset($_POST['approval_id']) ? sanitize_text_field(wp_unslash($_POST['approval_id'])) : '';
        check_admin_referer($action . $approvalId);

        return $approvalId;
    }

    private function redirectBack(): void
    {
        wp_safe_redirect(add_query_arg(['page' => self::SLUG], admin_url('tools.php')));
        exit;
    }
}
