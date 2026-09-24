<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Audit\AuditDecision;
use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;

/**
 * Audit emission for changes to the plugin's own enforcement state: a pack
 * entering or leaving shadow mode, a credential being bound to a different
 * pack, the emergency stop being thrown or lifted, and a tripwire locking a
 * credential out. These decide how every later call is judged, so they belong
 * in the same hash chain as the calls — without them the log can show an agent
 * running as `owner` but not who widened it, or a week of dry-run rows but not
 * who switched enforcement off.
 *
 * Same record shape as {@see GrantRecorder}: the event name rides in `reason`
 * (`shadow.enabled` / `shadow.disabled` / `shadow.expired` /
 * `binding.changed` / `pause.enabled` / `pause.disabled` / `tripwire.locked`),
 * the option or subject that changed rides in `ability`, the details in
 * `input`, and `decision` is the single {@see AuditDecision::Admin} value.
 * The actor is the request's own ({@see RequestContext::actor()}): inside
 * wp-admin that is the logged-in user, and for the cron sweep, a tripwire or
 * a WP-CLI pause no user at all — which is why the pause events also carry
 * the user id the caller named in `input`.
 *
 * No-op without a sink, like {@see DecisionRecorder}.
 */
final class AdminChangeRecorder
{
    public const EVENT_SHADOW_ENABLED = 'shadow.enabled';
    public const EVENT_SHADOW_DISABLED = 'shadow.disabled';
    public const EVENT_SHADOW_EXPIRED = 'shadow.expired';
    public const EVENT_BINDING_CHANGED = 'binding.changed';
    public const EVENT_PAUSE_ENABLED = 'pause.enabled';
    public const EVENT_PAUSE_DISABLED = 'pause.disabled';
    public const EVENT_TRIPWIRE_LOCKED = 'tripwire.locked';
    public const EVENT_ENVIRONMENT_BOUND = 'environment.bound';
    public const EVENT_ENVIRONMENT_MISMATCH = 'environment.mismatch';
    public const EVENT_ENVIRONMENT_REBOUND = 'environment.rebound';
    public const EVENT_APPROVAL_VOID_ENVIRONMENT = 'approval.void_environment';
    public const EVENT_GRANTS_VOID_ENVIRONMENT = 'grants.void_environment';

    /**
     * Synthetic pack name for configuration events: no call is in flight, so
     * no pack has been resolved. Naming the subsystem keeps the NOT NULL
     * column honest instead of guessing a pack.
     */
    public const PACK = 'admin';

    public function __construct(private readonly ?AuditSink $sink = null)
    {
    }

    /** A human put $pack into log-only observation until $expiresAt (unix). */
    public function shadowEnabled(string $pack, int $expiresAt): void
    {
        $this->append(self::EVENT_SHADOW_ENABLED, ShadowMode::OPTION, ['pack' => $pack, 'expires_at' => $expiresAt]);
    }

    /** A human took $pack out of observation before its window lapsed. */
    public function shadowDisabled(string $pack): void
    {
        $this->append(self::EVENT_SHADOW_DISABLED, ShadowMode::OPTION, ['pack' => $pack]);
    }

    /**
     * $pack's stored entry stopped shadowing anything and was removed —
     * usually because its window lapsed, which $expiresAt shows; null when
     * the entry was malformed and never shadowed at all. $cause distinguishes
     * an environment-ceiling drop (AS-7 §3.4 item 10: the entry's own stamp
     * was still in the future, but a flip — e.g. this site is now production
     * — lowered the ceiling below it) from an ordinary TTL lapse (null).
     */
    public function shadowExpired(string $pack, ?int $expiresAt, ?string $cause = null): void
    {
        $input = ['pack' => $pack, 'expires_at' => $expiresAt];
        if ($cause !== null) {
            $input['cause'] = $cause;
        }

        $this->append(self::EVENT_SHADOW_EXPIRED, ShadowMode::OPTION, $input);
    }

    /**
     * The pack bound to $subject changed from $from to $to; null on either
     * side is the default pack (no stored binding).
     */
    public function bindingChanged(string $subject, ?string $from, ?string $to): void
    {
        $this->append(
            self::EVENT_BINDING_CHANGED,
            PackResolver::BINDINGS_OPTION,
            ['subject' => $subject, 'from' => $from, 'to' => $to],
        );
    }

    /** $byUserId stopped every governed call, giving $reason in their own words. */
    public function pauseEnabled(int $byUserId, string $reason): void
    {
        $this->append(self::EVENT_PAUSE_ENABLED, PauseSwitch::OPTION, ['by' => $byUserId, 'reason' => $reason]);
    }

    /** $byUserId lifted the stored pause. */
    public function pauseDisabled(int $byUserId): void
    {
        $this->append(self::EVENT_PAUSE_DISABLED, PauseSwitch::OPTION, ['by' => $byUserId]);
    }

    /** $token reached $denials denials within a minute and is locked out for $lockoutSeconds. */
    public function tripwireLocked(string $token, int $denials, int $lockoutSeconds): void
    {
        $this->append(
            self::EVENT_TRIPWIRE_LOCKED,
            Tripwires::LOCKOUT,
            ['token' => $token, 'denials' => $denials, 'lockout_seconds' => $lockoutSeconds],
        );
    }

    /** First site bind (activation, or the first request after a schema upgrade) — AS-7 §3.4 item 3. */
    public function environmentBound(string $host): void
    {
        $this->append(self::EVENT_ENVIRONMENT_BOUND, EnvironmentGuard::OPTION, ['host' => $host]);
    }

    /**
     * The ONE row for a detected site-binding mismatch (item 4): the bound
     * and current host, and how many of each Relaxation kind this call just
     * voided. The void itself runs once (guarded by a lock the caller owns);
     * this is that single summary row, not one per relaxation.
     */
    public function environmentMismatch(string $boundHost, string $currentHost, int $shadowVoided, int $grantsVoided, int $approvalsVoided): void
    {
        $this->append(self::EVENT_ENVIRONMENT_MISMATCH, EnvironmentGuard::OPTION, [
            'bound_host' => $boundHost,
            'current_host' => $currentHost,
            'shadow_voided' => $shadowVoided,
            'grants_voided' => $grantsVoided,
            'approvals_voided' => $approvalsVoided,
        ]);
    }

    /** An administrator rebound the site on the settings page (item 7); restores nothing. */
    public function environmentRebound(string $oldHost, string $newHost): void
    {
        $this->append(self::EVENT_ENVIRONMENT_REBOUND, EnvironmentGuard::OPTION, ['from' => $oldHost, 'to' => $newHost]);
    }

    /** One approved-but-unclaimed Approval voided by a site-binding mismatch (item 5). */
    public function approvalVoidedEnvironment(string $approvalId, string $verb): void
    {
        $this->append(self::EVENT_APPROVAL_VOID_ENVIRONMENT, $verb, ['approval_id' => $approvalId]);
    }

    /** Every still-live Grant revoked in one site-binding mismatch (item 5) — one row, the count. */
    public function grantsVoidedEnvironment(int $count): void
    {
        $this->append(self::EVENT_GRANTS_VOID_ENVIRONMENT, 'grants', ['count' => $count]);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function append(string $event, string $option, array $input): void
    {
        if ($this->sink === null) {
            return;
        }

        $this->sink->append(AuditRecord::decision(
            id: RequestContext::event(),
            ts: RequestContext::nowUtc(),
            correlationId: RequestContext::correlation(),
            pack: self::PACK,
            actor: RequestContext::actor(),
            ability: $option,
            tier: null,
            input: $input,
            decision: AuditDecision::Admin,
            approval: null,
            ip: RequestContext::ip(),
            reason: $event,
        ));
    }
}
