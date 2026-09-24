<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Privacy;

use WP_User;

/**
 * `wp_privacy_personal_data_erasers` callback (§3.6 item 2): erases NOTHING.
 * The audit log is an append-only hash chain — {@see
 * \Specflux\AgentSafety\Audit\HashChain} links each row's `entry_hash` from
 * the one before it, so rewriting or dropping a row to satisfy an erasure
 * request would break that chain for every row recorded after it. A matching
 * user's rows are instead reported `items_retained`, with the reason stated
 * back to the requester, per the same paging contract as {@see
 * PersonalDataExporter}.
 */
final class PersonalDataEraser
{
    private const PAGE_SIZE = 200;

    public function __construct(private readonly PrivacyAuditReader $reader)
    {
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public function erase(string $emailAddress, int $page = 1): array
    {
        $user = get_user_by('email', $emailAddress);
        if (!$user instanceof WP_User) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        $page = max(1, $page);
        $rows = $this->reader->pageForUser($user->ID, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);

        if ($rows === []) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }

        return [
            'items_removed' => false,
            'items_retained' => true,
            'messages' => [
                __(
                    'Agent Safety audit records for this user are kept as a tamper-evident security record: each entry is chained to the one before it by hash, so altering or removing one would break that chain for every entry recorded after it.',
                    'agent-safety'
                ),
            ],
            'done' => count($rows) < self::PAGE_SIZE,
        ];
    }
}
