<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Packs\Pack;

/**
 * Two anomaly tripwires the rate limits cannot express, because a denial
 * never consumes quota (D26) and so a looping agent is never rate-limited by
 * its own refusals:
 *
 *  - Denial lockout. Every denial the core gate itself produces (unknown
 *    verb, not in pack, class-denied, a lying or destructive hint) is counted
 *    per identity token in a one-minute window; when the count reaches
 *    `denials_per_minute` the token is locked out for `lockout_seconds`, one
 *    `tripwire.locked` audit row is written, `agent_safety_tripwire_tripped`
 *    fires, and the approval recipient is emailed. While locked out, every
 *    governed call for that token — any tier, any would-be outcome — is
 *    denied `denial_lockout`. Lockout denials do not feed the count.
 *
 *  - Repeat-call guard. A write (tier >= 1) the same credential issues with
 *    the same arguments more than `identical_calls_per_hour` times in an hour
 *    is denied `repeat_call`. Identity is the approval binding's hash, so a
 *    threaded `_approval` token or a reordered key is still the same call.
 *    A refused repeat does not count (D26).
 *
 * Thresholds come from the `agent_safety_tripwire_limits` filter over
 * {@see DEFAULTS}; a value that is not a positive int keeps the default for
 * that key. The counters are per-request memoized the way {@see RateLimitGate}
 * is, because WordPress re-enters a permission callback many times per
 * request and both gate seams may judge one call: a logical call is counted
 * once however often it is re-checked. Bare time() for the test clock, as in
 * {@see RateCounter}.
 */
final class Tripwires
{
    public const FILTER = 'agent_safety_tripwire_limits';

    public const ACTION = 'agent_safety_tripwire_tripped';

    /** The deny reason for a locked-out token; also names the lockout in `agent_safety_tripwire_tripped`. */
    public const LOCKOUT = 'denial_lockout';

    /** The deny reason for one identical write too many. */
    public const REPEAT = 'repeat_call';

    public const DEFAULTS = [
        'denials_per_minute' => 20,
        'lockout_seconds' => 600,
        'identical_calls_per_hour' => 5,
    ];

    private const DENIAL_WINDOW = 60;
    private const REPEAT_WINDOW = 3600;

    /** Bucket used for a call with no resolvable identity token. */
    private const ANONYMOUS_TOKEN = '(anonymous)';

    // Public: named by {@see UninstallManifest} as the transient-key prefix
    // an opted-in uninstall must sweep (this key is per identity, so no
    // fixed list of full names exists).
    public const LOCKOUT_PREFIX = 'agsafe_lock_';

    /** @var array<string, true> identity|verb|args already counted as a denial in THIS request. */
    private array $countedDenials = [];

    /** @var array<string, bool> repeat-guard verdict (true = admitted) per identical call in THIS request. */
    private array $admitted = [];

    public function __construct(
        private readonly WindowCounter $counter = new WindowCounter(),
        private readonly AdminChangeRecorder $changes = new AdminChangeRecorder(),
        private readonly ?ApprovalNotifier $notifier = null,
    ) {
    }

    public function isLockedOut(?string $token): bool
    {
        return get_transient($this->lockoutKey($token ?? self::ANONYMOUS_TOKEN)) !== false;
    }

    /**
     * Count one gate denial against $token and lock it out when the minute's
     * count reaches the threshold. Meant for a decision the core gate itself
     * denied — never the pause or lockout reasons, never an approval park —
     * and counts once per logical call in this request. A token already
     * locked out is not counted at all, so the lockout can only be re-tripped
     * by denials that arrive after it lapses.
     *
     * @param array<string, mixed> $args
     */
    public function recordDenial(?string $token, string $verb, array $args): void
    {
        $identity = $token ?? self::ANONYMOUS_TOKEN;
        $memoKey = $identity . '|' . $verb . '|' . md5(serialize($args));
        if (isset($this->countedDenials[$memoKey]) || $this->isLockedOut($token)) {
            return;
        }
        $this->countedDenials[$memoKey] = true;

        $limits = $this->limits();
        $denials = $this->counter->increment('denials|' . $identity, self::DENIAL_WINDOW);
        if ($denials >= $limits['denials_per_minute']) {
            $this->lock($identity, $denials, $limits['lockout_seconds']);
        }
    }

    /**
     * The repeat-call guard for a decision that is otherwise Allow on a
     * write. True when admitted — the call has been counted as one more
     * identical call this hour, exactly once per logical call — or false
     * when it is one too many, in which case nothing was counted.
     *
     * @param array<string, mixed> $args
     */
    public function admit(Pack $pack, ?string $token, string $verb, array $args): bool
    {
        $identity = $token ?? self::ANONYMOUS_TOKEN;
        $subject = 'repeat|' . $pack->name . '|' . $identity . '|' . $verb . '|' . ApprovalBinding::hash($verb, $args);
        if (array_key_exists($subject, $this->admitted)) {
            return $this->admitted[$subject];
        }

        if ($this->counter->count($subject, self::REPEAT_WINDOW) >= $this->limits()['identical_calls_per_hour']) {
            return $this->admitted[$subject] = false;
        }

        $this->counter->increment($subject, self::REPEAT_WINDOW);

        return $this->admitted[$subject] = true;
    }

    /**
     * The effective thresholds: {@see DEFAULTS} after the
     * `agent_safety_tripwire_limits` filter, each key falling back to its
     * default unless the filter returned a positive int for it.
     *
     * @return array{denials_per_minute: int, lockout_seconds: int, identical_calls_per_hour: int}
     */
    public function limits(): array
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is the literal, prefixed 'agent_safety_tripwire_limits' constant; PHPCS can't resolve a class constant statically.
        $filtered = apply_filters(self::FILTER, self::DEFAULTS);

        return [
            'denials_per_minute' => $this->positiveInt($filtered, 'denials_per_minute'),
            'lockout_seconds' => $this->positiveInt($filtered, 'lockout_seconds'),
            'identical_calls_per_hour' => $this->positiveInt($filtered, 'identical_calls_per_hour'),
        ];
    }

    private function positiveInt(mixed $filtered, string $key): int
    {
        $value = is_array($filtered) ? ($filtered[$key] ?? null) : null;

        return is_int($value) && $value > 0 ? $value : self::DEFAULTS[$key];
    }

    private function lock(string $identity, int $denials, int $lockoutSeconds): void
    {
        $until = time() + $lockoutSeconds;
        set_transient($this->lockoutKey($identity), $until, $lockoutSeconds);

        $this->changes->tripwireLocked($identity, $denials, $lockoutSeconds);

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::ACTION is the literal, prefixed 'agent_safety_tripwire_tripped' constant; PHPCS can't resolve a class constant statically.
        do_action(self::ACTION, $identity, self::LOCKOUT, [
            'token' => $identity,
            'denials' => $denials,
            'lockout_seconds' => $lockoutSeconds,
            'until' => $until,
        ]);

        $this->notifier?->alert(
            sprintf(
                /* translators: 1: denial count, 2: the locked-out credential/token identity */
                __('Credential locked out after %1$d denials: %2$s', 'agent-safety'),
                $denials,
                $identity
            ),
            sprintf(
                /* translators: 1: token identity, 2: denials in the last minute, 3: lockout seconds, 4: the review URL */
                __("Agent Safety has locked out an agent credential.\n\nToken: %1\$s\nDenials in the last minute: %2\$d\nLocked out for: %3\$d seconds\n\nEvery call it makes is denied until the lockout lapses. Review what it was refused (requires login):\n%4\$s\n", 'agent-safety'),
                $identity,
                $denials,
                $lockoutSeconds,
                admin_url('tools.php?page=agent-safety-audit'),
            ),
        );
    }

    private function lockoutKey(string $identity): string
    {
        return self::LOCKOUT_PREFIX . substr(md5($identity), 0, 20);
    }
}
