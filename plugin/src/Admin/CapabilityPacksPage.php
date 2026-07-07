<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Identity\IdentityProvider;
use Specflux\AgentSafety\Plugin\Support\PackResolver;

/**
 * Tools → "Agent Capability Packs" (SPEC §3): the human side of credential scoping.
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
 * so prompt injection cannot reach it (D9).
 */
final class CapabilityPacksPage
{
    private const SLUG = 'agent-safety-packs';
    private const CAP = 'manage_options';
    private const SAVE = 'agsafe_save_pack_bindings';

    public function __construct(
        private readonly PackResolver $packs,
        private readonly IdentityChain $identity,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::SAVE, [$this, 'save']);
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
        echo '<p>' . esc_html__('A pack is a credentialed, purpose-scoped view of the verb catalog (SPEC §3). Enforced in the gate, not via WP roles. A pack that denies a tier class is injection-proof against that class by construction.', 'agent-safety') . '</p>';

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

        echo '<h2>' . esc_html__('Pack catalog', 'agent-safety') . '</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['Pack', 'Allows', 'Hard-denied (deny_class)', 'Approval-gated', 'PII'] as $col) {
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
            echo '</tr>';
        }

        echo '</tbody></table>';
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

        $valid = $this->packs->registry()->names();
        $posted = isset($_POST['bindings']) && is_array($_POST['bindings']) ? wp_unslash($_POST['bindings']) : [];

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

        wp_safe_redirect(add_query_arg(
            ['page' => self::SLUG, 'agsafe_saved' => '1'],
            admin_url('tools.php')
        ));
        exit;
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
