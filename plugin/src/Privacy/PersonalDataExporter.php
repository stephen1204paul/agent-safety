<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Privacy;

use WP_User;

/**
 * `wp_privacy_personal_data_exporters` callback (§3.6 item 2): resolves the
 * requested email to a WordPress user and exports THEIR audit rows only,
 * paging through {@see PrivacyAuditReader} per WordPress's exporter paging
 * contract — keep returning `done => false` until a page comes back short of
 * {@see self::PAGE_SIZE}. No user for the email → no data, done immediately.
 */
final class PersonalDataExporter
{
    private const PAGE_SIZE = 200;

    public function __construct(private readonly PrivacyAuditReader $reader)
    {
    }

    /**
     * @return array{
     *     data: list<array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}>,
     *     done: bool
     * }
     */
    public function export(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        if (!$user instanceof WP_User) {
            return ['data' => [], 'done' => true];
        }

        $page = max(1, $page);
        $rows = $this->reader->pageForUser($user->ID, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        $data = [];
        foreach ($rows as $row) {
            $data[] = self::group($row);
        }

        return ['data' => $data, 'done' => count($rows) < self::PAGE_SIZE];
    }

    /**
     * One export group per audit row: the fields useful to the requester,
     * decoded straight from `record_json` (the same source {@see
     * \Specflux\AgentSafety\Plugin\Admin\AuditLogPage} reads for its table).
     *
     * @param array<string, mixed> $row
     * @return array{group_id: string, group_label: string, item_id: string, data: list<array{name: string, value: string}>}
     */
    private static function group(array $row): array
    {
        $decoded = json_decode((string) ($row['record_json'] ?? '{}'), true);
        $record = is_array($decoded) ? $decoded : [];

        return [
            'group_id' => 'agent-safety-audit-log',
            'group_label' => __('Agent Safety Audit Log', 'agent-safety'),
            'item_id' => 'agent-safety-audit-log-' . (string) ($row['id'] ?? ''),
            'data' => [
                ['name' => __('Time', 'agent-safety'), 'value' => (string) ($record['ts'] ?? '')],
                ['name' => __('Ability', 'agent-safety'), 'value' => (string) ($record['ability'] ?? '')],
                ['name' => __('Tier', 'agent-safety'), 'value' => isset($record['tier']) ? (string) $record['tier'] : ''],
                ['name' => __('Decision', 'agent-safety'), 'value' => (string) ($record['decision'] ?? '')],
                ['name' => __('Reason', 'agent-safety'), 'value' => (string) ($record['reason'] ?? '')],
                ['name' => __('IP Address', 'agent-safety'), 'value' => (string) ($record['ip'] ?? '')],
                ['name' => __('Recorded Input', 'agent-safety'), 'value' => (string) wp_json_encode($record['input'] ?? [])],
            ],
        ];
    }
}
