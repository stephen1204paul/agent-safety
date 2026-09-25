<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * The site-wide emergency stop. While paused, every governed call of every
 * tier is denied `site_paused` before any approval is claimed or any budget
 * consulted, and the audit trail keeps recording — unlike deactivating the
 * plugin, which removes the gate and the log with it, or rebinding
 * credentials one at a time.
 *
 * Two inputs, both administrator- or host-controlled: the `agsafe_paused`
 * option (absent or false when not paused; when paused, who, when and why)
 * and the `agent_safety_paused` filter. The filter can only FORCE a pause —
 * honoured on a literal `true`, nothing else — and can never clear a stored
 * one, so a host can add a stop of its own but not lift the operator's. A
 * stored value that is any non-empty array pauses, however malformed: a
 * hand-edited option fails towards stopped.
 *
 * Pause is absolute. Shadow mode, which lets a shadowed pack's would-be
 * denial proceed as a dry run, does not apply to it
 * ({@see \Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline}).
 *
 * Calls bare time() so the test clock in tests/stubs/wpas-clock.php applies
 * (see {@see RateCounter} for why the unqualified call is deliberate).
 */
final class PauseSwitch
{
    public const OPTION = 'agsafe_paused';

    public const FILTER = 'agent_safety_paused';

    /** The deny reason every governed call carries while the site is paused. */
    public const REASON = 'site_paused';

    public function __construct(private readonly AdminChangeRecorder $changes = new AdminChangeRecorder())
    {
    }

    public function isPaused(): bool
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- self::FILTER is the literal, prefixed 'agent_safety_paused' constant; PHPCS can't resolve a class constant statically.
        return $this->state() !== null || apply_filters(self::FILTER, false) === true;
    }

    /**
     * The stored pause, or null when the option holds none. A filter-forced
     * pause has no record here — {@see isPaused()} is the only truth about
     * whether calls are stopped — and a field the option lacks or holds in
     * the wrong type comes back null or empty rather than coerced.
     *
     * @return array{since: ?int, by: ?int, reason: string}|null
     */
    public function state(): ?array
    {
        $raw = get_option(self::OPTION, false);
        if (!is_array($raw) || $raw === []) {
            return null;
        }

        $since = $raw['since'] ?? null;
        $by = $raw['by'] ?? null;
        $reason = $raw['reason'] ?? null;

        return [
            'since' => is_int($since) ? $since : null,
            'by' => is_int($by) ? $by : null,
            'reason' => is_string($reason) ? $reason : '',
        ];
    }

    /** Stop every governed call, recording who asked and why. */
    public function pause(int $byUserId, string $reason): void
    {
        update_option(self::OPTION, ['since' => time(), 'by' => $byUserId, 'reason' => $reason], false);
        $this->changes->pauseEnabled($byUserId, $reason);
    }

    /**
     * Lift a stored pause. A pause the filter forces is untouched — nothing
     * here can clear it — and a site that was not paused is left as it is,
     * with nothing audited.
     */
    public function resume(int $byUserId): void
    {
        if ($this->state() === null) {
            return;
        }

        delete_option(self::OPTION);
        $this->changes->pauseDisabled($byUserId);
    }
}
