<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;

/**
 * Hourly cron sweep of the approvals table. Without it, expired-and-unactioned
 * approval rows accumulate forever — see
 * {@see WpdbApprovalStore::deleteExpired()} for exactly which rows qualify and
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

    /** The cron callback: sweep with the current UTC time. */
    public static function run(WpdbApprovalStore $store): void
    {
        $store->deleteExpired(gmdate('Y-m-d H:i:s'));
    }
}
