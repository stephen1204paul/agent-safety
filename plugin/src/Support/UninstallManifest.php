<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Plugin\Admin\PendingActionsPage;

/**
 * The single, explicit list of everything an opted-in uninstall
 * (`plugin/uninstall.php`, gated on `AGSAFE_REMOVE_DATA === true`) must
 * remove: every option, cron hook and transient-key prefix the plugin ever
 * writes.
 *
 * Every entry below is the real class constant that OWNS the name, never a
 * bare literal — so this list and the code that actually calls
 * `update_option()`/`wp_schedule_event()`/`set_transient()` can never
 * silently disagree about what a name IS, only about whether this list has
 * been kept in sync. A static-scan test
 * ({@see \Specflux\AgentSafety\Plugin\Tests\Support\UninstallManifestCoverageTest})
 * catches the second kind of drift: it walks every file under `plugin/src`
 * for option/cron/transient call sites, resolves any class-constant
 * argument by reflection, and fails naming anything found that is not
 * listed here.
 *
 * Framework-agnostic on purpose: this class calls no WordPress function, so
 * `uninstall.php` — which runs standalone, with no bootstrap guaranteed
 * beyond `$wpdb` and the WordPress uninstall API itself — can safely
 * `require` the Composer autoloader and read these constants even if
 * nothing else in the plugin ever ran.
 */
final class UninstallManifest
{
    /**
     * Every option this plugin get_option/update_option/add_option/
     * delete_option's, by name.
     *
     * @var list<string>
     */
    public const OPTIONS = [
        Schema::VERSION_OPTION,
        Schema::GRANTS_RENAME_CONFLICT_OPTION,
        PackResolver::BINDINGS_OPTION,
        PauseSwitch::OPTION,
        ShadowMode::OPTION,
        EnvironmentGuard::OPTION,
        EnvironmentGuard::LOCK_OPTION,
        EnvironmentGuard::VOIDED_FOR_OPTION,
        ApprovalNotifier::EMAIL_OPTION,
        ApprovalNotifier::WEBHOOK_OPTION,
    ];

    /**
     * Every wp-cron hook this plugin `wp_schedule_event()`/
     * `wp_schedule_single_event()`'s.
     *
     * @var list<string>
     */
    public const CRON_HOOKS = [
        ApprovalSweep::HOOK,
    ];

    /**
     * Transient-key PREFIXES, never full names: every transient this plugin
     * sets is bucketed per pack/identity/window/subject/approver, so no
     * fixed list of full names exists. Uninstall deletes by a `LIKE` match
     * against `wp_options` directly — `_transient_{prefix}%` for the value
     * row and `_transient_timeout_{prefix}%` for the timeout row (site
     * transients use the `_site_transient_` equivalents, but this plugin
     * never sets a site transient).
     *
     * @var list<string>
     */
    public const TRANSIENT_PREFIXES = [
        PendingActionsPage::FLASH,
        PendingActionsPage::STALE_FLASH,
        RateCounter::PREFIX,
        WindowCounter::PREFIX,
        ValueAccumulator::PREFIX,
        Tripwires::LOCKOUT_PREFIX,
    ];

    /**
     * The plugin's three current custom tables (base names, without the
     * `$wpdb` prefix) plus the pre-v4 grants table name a crashed upgrade
     * can leave behind ({@see Schema::renameLegacyGrantsTable()}) — an
     * opted-in uninstall drops all four if present.
     *
     * @var list<string>
     */
    public const TABLE_BASENAMES = [
        Schema::AUDIT_LOG_TABLE,
        Schema::APPROVALS_TABLE,
        Schema::GRANTS_TABLE,
        Schema::LEGACY_GRANTS_TABLE,
    ];
}
