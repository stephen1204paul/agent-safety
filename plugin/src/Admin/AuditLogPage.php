<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

use Specflux\AgentSafety\Plugin\Audit\AuditReader;

/**
 * Tools → "Agent Audit Log": a read-only wp-admin viewer for the append-only audit
 * trail (SPEC §5). Shows a tamper-evidence banner (re-verifies the hash chain on
 * every load), a paged table of recent events, and a CSV export of the full log.
 */
final class AuditLogPage
{
    private const SLUG = 'agent-safety-audit';
    private const PER_PAGE = 100;
    private const CAP = 'manage_options';
    private const EXPORT_ACTION = 'agsafe_export_audit';

    public function __construct(private readonly AuditReader $reader)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_' . self::EXPORT_ACTION, [$this, 'export']);
    }

    public function menu(): void
    {
        add_management_page(
            __('Agent Audit Log', 'agent-safety'),
            __('Agent Audit Log', 'agent-safety'),
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

        $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $total = $this->reader->total();
        $rows = $this->reader->latest(self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
        $intact = $this->reader->verifyChain();
        $pages = (int) ceil($total / self::PER_PAGE);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Agent Audit Log', 'agent-safety') . '</h1>';

        // Tamper-evidence banner.
        if ($intact) {
            printf(
                '<div class="notice notice-success inline"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Chain intact.', 'agent-safety'),
                esc_html(sprintf(/* translators: %d event count */ __('%d hash-chained events; no tampering detected.', 'agent-safety'), $total))
            );
        } else {
            printf(
                '<div class="notice notice-error inline"><p><strong>%s</strong> %s</p></div>',
                esc_html__('TAMPER DETECTED.', 'agent-safety'),
                esc_html__('The audit chain failed verification — a record was altered or deleted.', 'agent-safety')
            );
        }

        // Export.
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::EXPORT_ACTION) . '">';
        wp_nonce_field(self::EXPORT_ACTION);
        submit_button(__('Export CSV', 'agent-safety'), 'secondary', 'submit', false);
        echo '</form>';

        // Table.
        echo '<table class="widefat striped"><thead><tr>';
        foreach (['ID', 'Time (UTC)', 'Correlation', 'Ability', 'Tier', 'Decision', 'Result', 'Token', 'IP', 'Input'] as $col) {
            echo '<th>' . esc_html($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="10">' . esc_html__('No events yet.', 'agent-safety') . '</td></tr>';
        }

        foreach ($rows as $r) {
            $decoded = json_decode((string) ($r['record_json'] ?? '{}'), true);
            $token = is_array($decoded) && isset($decoded['actor']['token_id']) ? (string) $decoded['actor']['token_id'] : '';
            $input = is_array($decoded) && isset($decoded['input']) ? (string) wp_json_encode($decoded['input']) : '';

            echo '<tr>';
            echo '<td>' . esc_html((string) $r['id']) . '</td>';
            echo '<td>' . esc_html((string) $r['ts']) . '</td>';
            echo '<td><code>' . esc_html((string) $r['correlation_id']) . '</code></td>';
            echo '<td><code>' . esc_html((string) $r['ability']) . '</code></td>';
            echo '<td>' . esc_html($r['tier'] === null ? '—' : (string) $r['tier']) . '</td>';
            echo '<td>' . self::badge((string) $r['decision']) . '</td>';
            echo '<td>' . esc_html((string) ($r['result'] ?? '—')) . '</td>';
            echo '<td>' . esc_html($token ?: '—') . '</td>';
            echo '<td>' . esc_html((string) ($r['ip'] ?? '—')) . '</td>';
            echo '<td><code style="font-size:11px;">' . esc_html(self::truncate($input, 120)) . '</code></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        // Pagination.
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            for ($p = 1; $p <= $pages; $p++) {
                $url = esc_url(add_query_arg(['page' => self::SLUG, 'paged' => $p], admin_url('tools.php')));
                $label = $p === $paged ? '<strong>' . $p . '</strong>' : '<a href="' . $url . '">' . $p . '</a>';
                echo '<span style="margin:0 4px;">' . $label . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            echo '</div></div>';
        }

        echo '</div>';
    }

    /** Streams the full log as CSV and exits. */
    public function export(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die(esc_html__('Insufficient permissions.', 'agent-safety'));
        }
        check_admin_referer(self::EXPORT_ACTION);

        $rows = $this->reader->all();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=agent-audit-log.csv');

        $out = fopen('php://output', 'w');
        $cols = ['id', 'event_id', 'ts', 'correlation_id', 'pack', 'ability', 'tier', 'decision', 'result', 'wp_user', 'ip', 'record_json', 'prev_hash', 'entry_hash'];
        fputcsv($out, $cols);
        foreach ($rows as $r) {
            fputcsv($out, array_map(static fn ($c) => $r[$c] ?? '', $cols));
        }
        fclose($out);
        exit;
    }

    private static function badge(string $decision): string
    {
        $colors = [
            'allowed'  => '#46b450',
            'denied'   => '#dc3232',
            'pending'  => '#ffb900',
            'approved' => '#46b450',
            'rejected' => '#dc3232',
        ];
        $color = $colors[$decision] ?? '#888';

        return '<span style="color:#fff;background:' . esc_attr($color) . ';padding:1px 6px;border-radius:3px;font-size:11px;">'
            . esc_html($decision) . '</span>';
    }

    private static function truncate(string $s, int $len): string
    {
        return strlen($s) > $len ? substr($s, 0, $len) . '…' : $s;
    }
}
