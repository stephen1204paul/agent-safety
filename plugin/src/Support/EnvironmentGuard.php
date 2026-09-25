<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;

/**
 * Site binding (AS-7, §3.4, §3.9): the normalised host+port+path an install's
 * Relaxations (shadow windows, active Grants, approved-but-unclaimed
 * Approvals — see {@see ShadowMode}) were granted under. A mismatch voids
 * every Relaxation PERMANENTLY; Packs, tiers and the emergency stop are
 * untouched, because they were never host-bound to begin with.
 *
 * Wired at two points (item 4): {@see ensureCurrent()} runs on every governed
 * call (cheap in the common case: one or two option reads and a string
 * compare) and on `admin_init`.
 *
 * Security fix: the void used to be gated by a {@see LOCK_OPTION} that was
 * taken BEFORE the void ran and never cleared except by {@see rebind()}. That
 * stranded the site fail-OPEN two ways: a request that died mid-void left the
 * lock set with the void never retried, and a host that moved away and back
 * (A → B → A) kept the lock from the A→B move, so a LATER real move (A → C)
 * found `add_option()` already occupied and silently skipped the void —
 * Relaxations granted at A would survive a genuine move to C. Completion is
 * now tracked explicitly by {@see VOIDED_FOR_OPTION} (which host the void has
 * actually finished for) and the lock is short-lived: acquired only while a
 * void is in flight, released the moment it completes, and treated as stale
 * (and retaken) after {@see LOCK_TTL_SECONDS} if a crashed request left it
 * behind. Two concurrent requests can still never both run
 * {@see voidRelaxations()} for the same move — see {@see acquireLock()}.
 */
final class EnvironmentGuard
{
    public const OPTION = 'agsafe_site_binding';

    /**
     * Held only while a void is actually running: `add_option()`'s
     * atomicity lets only the first of any concurrent callers acquire it
     * (see {@see acquireLock()}). Its value is the unix timestamp it was
     * acquired at, used only to decide whether a lock left behind by a
     * crashed request has gone stale. Deleted the moment the void
     * completes — see {@see ensureCurrent()} — and also by {@see rebind()}.
     */
    public const LOCK_OPTION = 'agsafe_env_mismatch_lock';

    /**
     * A lock older than this is assumed abandoned by a request that died
     * mid-void (never reached the `delete_option()` at the end) rather than
     * one still genuinely in flight, and is retaken rather than honoured
     * forever.
     */
    private const LOCK_TTL_SECONDS = 60;

    /**
     * The host the void has ACTUALLY COMPLETED for (never autoloaded) —
     * distinct from {@see OPTION} (the bound host itself, which only ever
     * changes on {@see rebind()}). While the current host still equals this
     * value, {@see ensureCurrent()} returns immediately without touching the
     * lock at all: the void for THIS mismatch is done. Cleared whenever the
     * current host again matches the binding (a later move to the SAME
     * mismatched host must void again, since Relaxations may have been
     * granted in the meantime) and by {@see rebind()}.
     */
    public const VOIDED_FOR_OPTION = 'agsafe_site_binding_voided_for';

    public function __construct(
        private readonly ShadowMode $shadow,
        private readonly AdminChangeRecorder $changes,
        private readonly ?WpdbApprovalStore $approvals = null,
        private readonly ?WpdbGrantStore $grants = null,
    ) {
    }

    /** The install's current home URL, normalised the same way as the stored binding. */
    public function currentHost(): string
    {
        return SiteBinding::normalize(function_exists('home_url') ? (string) home_url() : '');
    }

    /** The stored binding, or null if this site has never been bound (should only be true pre-activation). */
    public function boundHost(): ?string
    {
        $stored = get_option(self::OPTION, null);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * First bind (item 3): activation, or the first request after a schema
     * upgrade for an existing install. A no-op once bound — existing
     * Relaxations are kept, never re-audited.
     */
    public function firstBindIfNeeded(): void
    {
        if ($this->boundHost() !== null) {
            return;
        }

        $host = $this->currentHost();
        update_option(self::OPTION, $host, false);
        $this->changes->environmentBound($host);
    }

    /** True while the bound host and the current one disagree — drives the admin notice and the settings-page banner. */
    public function isMismatched(): bool
    {
        $bound = $this->boundHost();

        return $bound !== null && $bound !== $this->currentHost();
    }

    /**
     * The check every governed call and `admin_init` make (item 4).
     *
     * Match path (current === bound): cheap — one extra `get_option()` for
     * {@see VOIDED_FOR_OPTION}, deleted if set so a LATER move to the same
     * mismatched host voids again (Relaxations may have been granted at the
     * bound host meanwhile).
     *
     * Mismatch path: a no-op the moment {@see VOIDED_FOR_OPTION} already
     * equals the current host — the void for THIS exact mismatch is done.
     * Otherwise {@see acquireLock()} decides whether THIS request runs the
     * void; on success it does, records completion, and releases the lock —
     * all three voids ({@see ShadowMode::voidAll()},
     * {@see \Specflux\AgentSafety\Plugin\Approval\WpdbGrantStore::revokeAllActive()},
     * {@see \Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore::voidUnclaimedApprovals()})
     * are idempotent, so re-running one that partially completed on a
     * crashed request is safe.
     */
    public function ensureCurrent(): void
    {
        $bound = $this->boundHost();
        if ($bound === null) {
            $this->firstBindIfNeeded();

            return;
        }

        $current = $this->currentHost();
        if ($current === $bound) {
            if (get_option(self::VOIDED_FOR_OPTION, null) !== null) {
                delete_option(self::VOIDED_FOR_OPTION);
            }

            return;
        }

        if ($this->voidedFor() === $current) {
            return;
        }

        if (!$this->acquireLock()) {
            return;
        }

        $this->voidRelaxations($bound, $current);
        update_option(self::VOIDED_FOR_OPTION, $current, false);
        delete_option(self::LOCK_OPTION);
    }

    /**
     * An administrator rebinding on the settings page (item 7): capability
     * and nonce are the CALLER's job (CapabilityPacksPage), never this
     * class's. Binds to the current host and clears both the lock and the
     * voided-for marker so a FUTURE move can void again; restores NOTHING
     * that the earlier void took away.
     */
    public function rebind(): void
    {
        $old = $this->boundHost() ?? '';
        $new = $this->currentHost();

        update_option(self::OPTION, $new, false);
        delete_option(self::LOCK_OPTION);
        delete_option(self::VOIDED_FOR_OPTION);

        $this->changes->environmentRebound($old, $new);
    }

    /** The host {@see VOIDED_FOR_OPTION} currently records, or null if unset. */
    private function voidedFor(): ?string
    {
        $stored = get_option(self::VOIDED_FOR_OPTION, null);

        return is_string($stored) && $stored !== '' ? $stored : null;
    }

    /**
     * Atomic, race-free, and self-healing against a crashed holder.
     * `add_option()` only succeeds the FIRST time an option name is written,
     * so of any concurrently-racing callers exactly one wins outright. A
     * caller that loses checks the lock's age: younger than
     * {@see LOCK_TTL_SECONDS} means another request is genuinely voiding
     * right now (return false, do nothing); older means the request that
     * took it died before reaching {@see ensureCurrent()}'s
     * `delete_option()`, so it is deleted and retaken in one more attempt.
     * That second attempt can itself lose to another late-arriving racer —
     * this is a best-effort self-heal, not a re-entrant retry loop, so a
     * loss there is just treated as "someone else has it".
     */
    private function acquireLock(): bool
    {
        if (!function_exists('add_option')) {
            return false;
        }

        if (add_option(self::LOCK_OPTION, time(), '', false)) {
            return true;
        }

        $held = get_option(self::LOCK_OPTION, null);
        $age = is_numeric($held) ? time() - (int) $held : PHP_INT_MAX;
        if ($age < self::LOCK_TTL_SECONDS) {
            return false;
        }

        delete_option(self::LOCK_OPTION);

        return add_option(self::LOCK_OPTION, time(), '', false);
    }

    /** The banner shown until rebound (item 5's "admin notice"), for wp-admin only. */
    public function renderMismatchNotice(): void
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options') || !$this->isMismatched()) {
            return;
        }

        echo '<div class="notice notice-error"><p>'
            . esc_html__(
                "Agent Safety: this site's address changed since its shadow windows, grants and approved-but-unclaimed approvals were authorised. Those relaxations have been permanently voided. Rebind on the Agent Capability Packs page once this move is expected.",
                'agent-safety'
            )
            . '</p></div>';
    }

    /**
     * Void every Relaxation permanently (item 5): the shadow option emptied,
     * every still-live Grant revoked, every approved-but-unclaimed Approval
     * flipped to the terminal `void_environment` status. Pending
     * (unapproved) Approvals are left alone entirely — only a human's
     * already-granted-but-unused decision is a Relaxation. One audit row per
     * item plus the single overview row (§3.4 items 4-5).
     */
    private function voidRelaxations(string $bound, string $current): void
    {
        $shadowVoided = $this->shadow->voidAll();
        foreach ($shadowVoided as $pack) {
            $this->changes->shadowDisabled($pack);
        }

        $grantsVoided = $this->grants?->revokeAllActive() ?? 0;
        if ($grantsVoided > 0) {
            $this->changes->grantsVoidedEnvironment($grantsVoided);
        }

        $approvalsVoided = $this->approvals?->voidUnclaimedApprovals() ?? [];
        foreach ($approvalsVoided as $row) {
            $this->changes->approvalVoidedEnvironment($row['approval_id'], $row['verb']);
        }

        $this->changes->environmentMismatch($bound, $current, count($shadowVoided), $grantsVoided, count($approvalsVoided));
    }
}
