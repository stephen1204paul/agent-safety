<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;

/**
 * Hourly cron sweep of the approvals table — and, when grants are wired, of the
 * grants table too. Without it, expired-and-unactioned rows accumulate forever;
 * see {@see WpdbApprovalStore::deleteExpired()} and
 * {@see WpdbGrantStore::deleteExpired()} for exactly which rows qualify and
 * which are kept as audit anchors.
 *
 * {@see activate()}/{@see deactivate()} own only the wp-cron scheduling and are
 * deliberately free of any class dependency beyond this one, so
 * `plugin/agent-safety.php` can call them from its activation/deactivation
 * callbacks after nothing more than confirming the autoloader ran.
 */
final class ApprovalSweep
{
    public const HOOK = 'agsafe_sweep_approvals';

    /** Idempotent: a hook already on the cron schedule is left untouched. */
    public static function activate(): void
    {
        if (wp_next_scheduled(self::HOOK) === false) {
            wp_schedule_event(time(), 'hourly', self::HOOK);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    /**
     * The cron callback: sweep both tables with the SAME current UTC time, so a
     * grant and the approval it minted can never disagree about whether their
     * window has lapsed.
     */
    public static function run(WpdbApprovalStore $store, ?WpdbGrantStore $grants = null): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $store->deleteExpired($now);
        $grants?->deleteExpired($now);
    }
}
