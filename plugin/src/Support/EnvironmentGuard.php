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
 * call (one option read and a string compare, cheap) and on `admin_init`; the
 * void itself is guarded by {@see LOCK_OPTION}, an `add_option()` that only
 * the first request to see a NEW mismatch wins, so two concurrent requests
 * can never both run {@see voidRelaxations()} for the same move. The lock
 * persists until {@see rebind()} clears it, so the admin notice — and the
 * refusal to re-run the void — both hold until a human explicitly rebinds.
 */
final class EnvironmentGuard
{
    public const OPTION = 'agsafe_site_binding';

    /**
     * Set (never autoloaded) the moment a mismatch is first detected;
     * cleared only by {@see rebind()}. Its VALUE is the mismatched host that
     * triggered it, kept only for diagnostics — the lock's presence, not its
     * value, is what matters.
     */
    public const LOCK_OPTION = 'agsafe_env_mismatch_lock';

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
     * The cheap check every governed call and `admin_init` make (item 4): one
     * option read, one string compare. A genuinely new mismatch runs the void
     * exactly once, race-free, via the {@see LOCK_OPTION} atomic add.
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
            return;
        }

        // add_option() only succeeds the FIRST time this option name is
        // written; every later call on the same mismatch (this request's
        // re-entries, and every subsequent request until rebind) loses the
        // race harmlessly and returns without touching anything further.
        if (!function_exists('add_option') || !add_option(self::LOCK_OPTION, $current, '', false)) {
            return;
        }

        $this->voidRelaxations($bound, $current);
    }

    /**
     * An administrator rebinding on the settings page (item 7): capability
     * and nonce are the CALLER's job (CapabilityPacksPage), never this
     * class's. Binds to the current host and clears the mismatch lock so a
     * FUTURE move can void again; restores NOTHING that the earlier void
     * took away.
     */
    public function rebind(): void
    {
        $old = $this->boundHost() ?? '';
        $new = $this->currentHost();

        update_option(self::OPTION, $new, false);
        delete_option(self::LOCK_OPTION);

        $this->changes->environmentRebound($old, $new);
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
