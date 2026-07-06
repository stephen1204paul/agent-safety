<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Plugin\Admin;

use Specflux\WooAgentSafety\Packs\Pack;
use Specflux\WooAgentSafety\Plugin\Support\PackResolver;
use wpdb;

/**
 * Tools → "Agent Capability Packs" (SPEC §3): the human side of credential scoping.
 *
 * Shows the built-in pack catalog (read-only) and lets an admin bind each
 * WooCommerce API key to a pack. Bindings persist in the `was_pack_bindings`
 * option (key id => pack name) that {@see PackResolver} reads per request; an
 * unbound key falls back to the safe default pack.
 *
 * A pack with `deny_class: ["tier2"]` makes every irreversible verb unreachable
 * for the bound credential BY CONSTRUCTION — the gate denies it before approval,
 * so prompt injection cannot reach it (D9).
 */
final class CapabilityPacksPage
{
    private const SLUG = 'was-capability-packs';
    private const CAP = 'manage_options';
    private const SAVE = 'was_save_pack_bindings';

    public function __construct(
        private readonly PackResolver $packs,
        private readonly wpdb $db,
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
            __('Agent Capability Packs', 'woo-agent-safety'),
            __('Agent Capability Packs', 'woo-agent-safety'),
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
        echo '<h1>' . esc_html__('Agent Capability Packs', 'woo-agent-safety') . '</h1>';
        echo '<p>' . esc_html__('A pack is a credentialed, purpose-scoped view of the verb catalog (SPEC §3). Enforced in the gate, not via WP roles. A pack that denies a tier class is injection-proof against that class by construction.', 'woo-agent-safety') . '</p>';

        if (isset($_GET['was_saved'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Capability pack bindings saved.', 'woo-agent-safety') . '</p></div>';
        }

        $this->renderCatalog($registry->names());
        $this->renderBindingForm($registry->names(), $registry->defaultPack(), $registry->bindings());

        echo '</div>';
    }

    /** @param list<string> $names */
    private function renderCatalog(array $names): void
    {
        $registry = $this->packs->registry();

        echo '<h2>' . esc_html__('Pack catalog', 'woo-agent-safety') . '</h2>';
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
     * @param list<string>          $names
     * @param array<string, string> $bindings
     */
    private function renderBindingForm(array $names, string $default, array $bindings): void
    {
        $keys = $this->apiKeys();

        echo '<h2 style="margin-top:2em;">' . esc_html__('Credential bindings', 'woo-agent-safety') . '</h2>';
        echo '<p>' . esc_html__('Map each WooCommerce REST API key to a pack. Unbound keys use the default pack.', 'woo-agent-safety') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE) . '">';
        echo wp_nonce_field(self::SAVE, '_wpnonce', true, false); // phpcs:ignore WordPress.Security.EscapeOutput -- wp_nonce_field returns safe markup.

        echo '<table class="widefat striped"><thead><tr>';
        foreach (['API key', 'Description', 'WC permissions', 'Pack'] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if ($keys === []) {
            echo '<tr><td colspan="4">' . esc_html__('No WooCommerce REST API keys found. Create one under WooCommerce → Settings → Advanced → REST API.', 'woo-agent-safety') . '</td></tr>';
        }

        foreach ($keys as $key) {
            $subject = 'key_' . $key['key_id'];
            $current = $bindings[$subject] ?? '';
            echo '<tr>';
            echo '<td><code>' . esc_html($subject) . '</code></td>';
            echo '<td>' . esc_html($key['description']) . '</td>';
            echo '<td><code>' . esc_html($key['permissions']) . '</code></td>';
            echo '<td>' . $this->packSelect($subject, $names, $default, $current) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput -- esc_* in helper.
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('Save bindings', 'woo-agent-safety') . '</button></p>';
        echo '</form>';
    }

    /**
     * @param list<string> $names
     */
    private function packSelect(string $subject, array $names, string $default, string $current): string
    {
        $out = '<select name="bindings[' . esc_attr($subject) . ']">';
        $out .= '<option value="">' . esc_html(sprintf(/* translators: %s default pack name */ __('(default — %s)', 'woo-agent-safety'), $default)) . '</option>';
        foreach ($names as $name) {
            $out .= '<option value="' . esc_attr($name) . '"' . selected($current, $name, false) . '>' . esc_html($name) . '</option>';
        }
        $out .= '</select>';

        return $out;
    }

    public function save(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'woo-agent-safety'));
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
            ['page' => self::SLUG, 'was_saved' => '1'],
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

    /**
     * Live WooCommerce REST API keys (D20: each key is a principal). The secret is
     * never read — only the row id, description, and permission scope.
     *
     * @return list<array{key_id:string, description:string, permissions:string}>
     */
    private function apiKeys(): array
    {
        $table = $this->db->prefix . 'woocommerce_api_keys';
        $rows = $this->db->get_results(
            "SELECT key_id, description, permissions FROM {$table} ORDER BY key_id ASC",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'key_id' => (string) ($r['key_id'] ?? ''),
                'description' => (string) ($r['description'] ?? ''),
                'permissions' => (string) ($r['permissions'] ?? ''),
            ];
        }

        return $out;
    }
}
