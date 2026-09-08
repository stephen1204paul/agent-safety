<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Identity\IdentityProvider;
use Specflux\AgentSafety\Plugin\Support\AdminChangeRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;

/**
 * Tools → "Agent Capability Packs": the human side of credential scoping.
 *
 * Shows the pack catalog (read-only) and lets an admin bind each identity a
 * configured {@see IdentityProvider} exposes (an application password, a user,
 * a role, or an integration's own credential — e.g. a WooCommerce API key) to
 * a pack from the catalog. One section per provider, using its {@see
 * IdentityProvider::label()} and {@see IdentityProvider::bindableTokens()}.
 * Bindings persist in the `agsafe_pack_bindings` option (token id => pack name)
 * that {@see PackResolver} reads per request; an unbound token falls back to
 * the safe default pack.
 *
 * A pack with `deny_class: ["tier2"]` makes every irreversible verb unreachable
 * for the bound credential BY CONSTRUCTION — the gate denies it before approval,
 * so prompt injection cannot reach it.
 *
 * Every change saved here is written to the audit chain through
 * {@see AdminChangeRecorder}, one row per binding or pack that actually
 * changed: the log must be able to say who widened a credential or switched
 * enforcement off, not only what the agent did afterwards.
 */
final class CapabilityPacksPage
{
    private const SLUG = 'agent-safety-packs';
    private const CAP = 'manage_options';
    private const SAVE = 'agsafe_save_pack_bindings';
    private const SHADOW = 'agsafe_save_shadow_packs';

    /** Observation windows offered on the shadow form, in days; the last is the default. */
    private const SHADOW_DAYS = [1, 3, 7];

    public function __construct(
        private readonly PackResolver $packs,
        private readonly IdentityChain $identity,
        private readonly ShadowMode $shadow = new ShadowMode(),
        private readonly AdminChangeRecorder $changes = new AdminChangeRecorder(),
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::SAVE, [$this, 'save']);
        add_action('admin_post_' . self::SHADOW, [$this, 'saveShadow']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Agent Capability Packs', 'agent-safety'),
            __('Agent Capability Packs', 'agent-safety'),
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

        $registry = $this->packs->registry();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Agent Capability Packs', 'agent-safety') . '</h1>';
        echo '<p>' . esc_html__('A pack is a credentialed, purpose-scoped view of the verb catalog. Enforced in the gate, not via WP roles. A pack that denies a tier class is injection-proof against that class by construction.', 'agent-safety') . '</p>';

        if (isset($_GET['agsafe_saved'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Capability pack bindings saved.', 'agent-safety') . '</p></div>';
        }

        $this->renderCatalog($registry->names());
        $this->renderBindings($registry->names(), $registry->defaultPack(), $registry->bindings());

        echo '</div>';
    }

    /** @param list<string> $names */
    private function renderCatalog(array $names): void
    {
        $registry = $this->packs->registry();
        // The checkbox reflects the STORED option — what this form controls —
        // so saving an untouched form changes nothing. A pack shadowed only by
        // the filter is noted, not ticked, or the save would mint a stored
        // window the administrator never chose.
        $stored = $this->shadow->storedExpiries();
        $effective = $this->shadow->expiries();

        echo '<h2>' . esc_html__('Pack catalog', 'agent-safety') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SHADOW) . '">';
        echo wp_nonce_field(self::SHADOW, '_wpnonce', true, false); // phpcs:ignore WordPress.Security.EscapeOutput -- core-built hidden fields.
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Pack', 'Allows', 'Hard-denied (deny_class)', 'Approval-gated', 'PII', 'Shadow (log only)'] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($names as $name) {
            $pack = $registry->get($name);
            if (!$pack instanceof Pack) {
                continue;
            }
            echo '<tr>';
            echo '<td><code>' . esc_html($pack->name) . '</code></td>';
            echo '<td>' . $this->codeList($pack->allow) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html in helper.
            echo '<td>' . ($pack->denyClass === [] ? '—' : $this->codeList($pack->denyClass)) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html in helper.
            echo '<td>' . $this->codeList(array_keys(array_filter($pack->approvalByClass))) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html in helper.
            echo '<td>' . esc_html($pack->pii) . '</td>';
            $expiresAt = $stored[$pack->name] ?? null;
            printf(
                '<td><label><input type="checkbox" name="shadow[]" value="%s"%s> %s</label>%s</td>',
                esc_attr($pack->name),
                checked($expiresAt !== null, true, false),
                esc_html__('audit only, enforce nothing', 'agent-safety'),
                $this->expiryNote($expiresAt, $effective[$pack->name] ?? null), // phpcs:ignore WordPress.Security.EscapeOutput -- esc_html in helper.
            );
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p>' . esc_html__('A shadowed pack still audits every verdict (marked dry_run) but blocks nothing. Observation ends on its own when the chosen window lapses (7 days at most) and enforcement resumes; every toggle is written to the audit log. Pending approvals are not created for shadowed calls.', 'agent-safety') . '</p>';
        echo '<p><label>' . esc_html__('Shadow newly ticked packs for', 'agent-safety') . ' <select name="shadow_days">';
        foreach (self::SHADOW_DAYS as $days) {
            printf(
                '<option value="%d"%s>%s</option>',
                $days,
                selected($days, self::defaultShadowDays(), false),
                esc_html(sprintf(
                    /* translators: %d number of days */
                    _n('%d day', '%d days', $days, 'agent-safety'),
                    $days
                )),
            );
        }
        echo '</select></label></p>';
        echo '<p><button type="submit" class="button">' . esc_html__('Save shadow mode', 'agent-safety') . '</button></p>';
        echo '</form>';
    }

    public function saveShadow(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'agent-safety'));
        }
        check_admin_referer(self::SHADOW);

        $posted = isset($_POST['shadow']) && is_array($_POST['shadow']) ? wp_unslash($_POST['shadow']) : [];
        $days = isset($_POST['shadow_days']) && is_scalar($_POST['shadow_days'])
            ? (int) wp_unslash($_POST['shadow_days'])
            : self::defaultShadowDays();

        $this->applyShadow($posted, $days);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'agsafe_saved' => '1'],
            admin_url('tools.php')
        ));
        exit;
    }

    /**
     * Sanitise and persist the shadow form, auditing each pack whose state
     * changed — lapsed entries the save swept up as `shadow.expired`, unticked
     * packs as `shadow.disabled`, newly ticked packs as `shadow.enabled` with
     * their expiry. Split from {@see saveShadow()} so the persistence and its
     * audit rows can be exercised without a nonce, a redirect or an exit.
     *
     * A duration outside {@see SHADOW_DAYS} falls to the SHORTEST offered,
     * not the default: shadow mode loosens enforcement, so a malformed choice
     * buys the least of it.
     *
     * @param array<mixed> $posted The raw `shadow[]` values.
     */
    public function applyShadow(array $posted, int $days): void
    {
        $valid = $this->packs->registry()->names();

        $clean = [];
        foreach ($posted as $pack) {
            $pack = sanitize_text_field((string) $pack);
            if ($pack !== '' && in_array($pack, $valid, true) && !in_array($pack, $clean, true)) {
                $clean[] = $pack;
            }
        }
        if (!in_array($days, self::SHADOW_DAYS, true)) {
            $days = min(self::SHADOW_DAYS);
        }

        $changed = $this->shadow->apply($clean, $days * 24 * 60 * 60);

        foreach ($changed['expired'] as $pack => $expiresAt) {
            $this->changes->shadowExpired($pack, $expiresAt);
        }
        foreach ($changed['disabled'] as $pack) {
            $this->changes->shadowDisabled($pack);
        }
        foreach ($changed['enabled'] as $pack => $expiresAt) {
            $this->changes->shadowEnabled($pack, $expiresAt);
        }
    }

    private static function defaultShadowDays(): int
    {
        return max(self::SHADOW_DAYS);
    }

    /** The line under a pack's shadow checkbox: its stored expiry, else a filter-only shadow, else nothing. */
    private function expiryNote(?int $stored, ?int $effective): string
    {
        if ($stored !== null) {
            $note = sprintf(
                /* translators: %s expiry date and time (UTC) */
                __('expires %s', 'agent-safety'),
                gmdate('Y-m-d H:i', $stored) . ' UTC'
            );
        } elseif ($effective !== null) {
            $note = sprintf(
                /* translators: 1: filter hook name, 2: expiry date and time (UTC) */
                __('shadowed by the %1$s filter until %2$s', 'agent-safety'),
                ShadowMode::FILTER,
                gmdate('Y-m-d H:i', $effective) . ' UTC'
            );
        } else {
            return '';
        }

        return '<br><small>' . esc_html($note) . '</small>';
    }

    /**
     * One section per configured {@see IdentityProvider}. Renders a helpful
     * empty-state line instead of an empty form when NO provider has any
     * bindable token (e.g. WooCommerce inactive and no users beyond the admin).
     *
     * @param list<string>          $names
     * @param array<string, string> $bindings
     */
    private function renderBindings(array $names, string $default, array $bindings): void
    {
        echo '<h2 style="margin-top:2em;">' . esc_html__('Credential bindings', 'agent-safety') . '</h2>';
        echo '<p>' . esc_html__('Bind a credential or role to a pack. Unbound tokens use the default pack.', 'agent-safety') . '</p>';

        $providers = array_values(array_filter(
            $this->identity->providers(),
            static fn (IdentityProvider $provider): bool => $provider->bindableTokens() !== [],
        ));

        if ($providers === []) {
            echo '<p><em>' . esc_html__('No bindable credentials or roles were found yet. Bindings will appear here once an identity provider (a user, a role, or an active integration) has something to bind.', 'agent-safety') . '</em></p>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">';
        echo wp_nonce_field(self::SAVE, '_wpnonce', true, false); // phpcs:ignore WordPress.Security.EscapeOutput -- wp_nonce_field returns safe markup.

        foreach ($providers as $provider) {
            $this->renderProviderSection($provider, $names, $default, $bindings);
        }

        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save bindings', 'agent-safety') . '</button></p>';
        echo '</form>';
    }

    /**
     * @param list<string>          $names
     * @param array<string, string> $bindings
     */
    private function renderProviderSection(IdentityProvider $provider, array $names, string $default, array $bindings): void
    {
        $tokens = $provider->bindableTokens();

        echo '<h3>' . esc_html($provider->label()) . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Token', 'Description', 'Pack'] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($tokens as $token => $description) {
            $current = $bindings[$token] ?? '';
            echo '<tr>';
            echo '<td><code>' . esc_html($token) . '</code></td>';
            echo '<td>' . esc_html($description) . '</td>';
            echo '<td>' . $this->packSelect($token, $names, $default, $current) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_* in helper.
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param list<string> $names
     */
    private function packSelect(string $subject, array $names, string $default, string $current): string
    {
        $out = '<select name="bindings[' . esc_attr($subject) . ']">';
        $out .= '<option value="">' . esc_html(sprintf(/* translators: %s default pack name */ __('(default — %s)', 'agent-safety'), $default)) . '</option>';
        foreach ($names as $name) {
            $out .= '<option value="' . esc_attr($name) . '"' . selected($current, $name, false) . '>' . esc_html($name) . '</option>';
        }
        $out .= '</select>';

        return $out;
    }

    public function save(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'agent-safety'));
        }
        check_admin_referer(self::SAVE);

        $posted = isset($_POST['bindings']) && is_array($_POST['bindings']) ? wp_unslash($_POST['bindings']) : [];

        $this->applyBindings($posted);

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'agsafe_saved' => '1'],
            admin_url('tools.php')
        ));
        exit;
    }

    /**
     * Sanitise and persist the bindings form, auditing one `binding.changed`
     * row per subject whose pack differs from what the option held — a
     * binding that is dropped back to the default counts as a change, a
     * binding re-saved unchanged does not. Split from {@see save()} for the
     * same reason as {@see applyShadow()}.
     *
     * @param array<mixed> $posted The raw `bindings[subject]` values.
     */
    public function applyBindings(array $posted): void
    {
        $valid = $this->packs->registry()->names();
        $before = $this->packs->storedBindings();

        $clean = [];
        foreach ($posted as $subject => $pack) {
            $subject = sanitize_text_field((string) $subject);
            $pack = sanitize_text_field((string) $pack);
            // Empty = "use default" (no binding stored); only persist valid named packs.
            if ($subject !== '' && $pack !== '' && in_array($pack, $valid, true)) {
                $clean[$subject] = $pack;
            }
        }

        update_option(PackResolver::BINDINGS_OPTION, $clean, false);
        $this->packs->flush();

        foreach (array_unique(array_merge(array_keys($before), array_keys($clean))) as $subject) {
            $from = $before[$subject] ?? null;
            $to = $clean[$subject] ?? null;
            if ($from !== $to) {
                $this->changes->bindingChanged((string) $subject, $from, $to);
            }
        }
    }

    /** @param list<string> $items */
    private function codeList(array $items): string
    {
        if ($items === []) {
            return '—';
        }

        return implode(' ', array_map(
            static fn (string $i): string => '<code style="font-size:11px;">' . esc_html($i) . '</code>',
            $items
        ));
    }
}
