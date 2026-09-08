<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Per-pack "log only" observation (roadmap 0.2 "shadow mode"): the gate
 * seams still evaluate and AUDIT every decision for a shadowed pack, but
 * enforce nothing — denials and approval-parks are recorded with the audit
 * record's dry_run marker and the call proceeds. Lets an existing site run a
 * week of observation (exactly what would have been denied or queued) before
 * turning enforcement on.
 *
 * This is the ONE feature in the plugin that deliberately loosens a verdict,
 * so its inputs are as explicit as the packs themselves: the
 * `agsafe_shadow_packs` option (set on the Agent Capability Packs screen by
 * an administrator) and the `agent_safety_shadow_packs` filter (site code).
 * Nothing a tool or agent self-reports can reach either.
 *
 * Shadowing EXPIRES. The option maps pack name => unix expiry timestamp, and a
 * pack is shadowed only while that timestamp is an int in the future and no
 * further ahead than {@see MAX_TTL}; anything else — the pre-expiry list shape,
 * a non-int, a lapsed stamp, a stamp beyond the ceiling — shadows nothing, so
 * enforcement resumes on its own. The filter is validated to the same rule
 * AFTER it runs, so site code cannot mint an unexpiring shadow either. The
 * sweep ({@see sweep()}) removes lapsed entries and audits each removal.
 *
 * Two enforcement side effects intentionally survive in shadow: an admitted
 * call still counts against rate/spend windows (so observed weeks predict
 * enforced ones), and a call whose verdict WOULD have been denied consumes
 * nothing — the same denial-doesn't-consume rule as enforcement (D26).
 * Pending approvals are NOT persisted for shadowed calls: minting approvable
 * grants for actions that already ran would corrupt the queue's meaning.
 */
final class ShadowMode
{
    public const OPTION = 'agsafe_shadow_packs';

    public const FILTER = 'agent_safety_shadow_packs';

    /**
     * The longest a pack may stay shadowed from one toggle: seven days, the
     * observation window the roadmap asks for. A stored stamp further ahead
     * than this is not honoured, so a hand-edited option cannot outlast it.
     */
    public const MAX_TTL = 7 * 24 * 60 * 60;

    public function isShadow(string $packName): bool
    {
        return array_key_exists($packName, $this->expiries());
    }

    /**
     * The pack names currently in log-only observation.
     *
     * @return list<string>
     */
    public function packs(): array
    {
        return array_keys($this->expiries());
    }

    /**
     * Pack name => unix expiry for every pack currently shadowed, after the
     * `agent_safety_shadow_packs` filter and with the filter's output held to
     * the same rule as the stored option.
     *
     * @return array<string, int>
     */
    public function expiries(): array
    {
        $now = time();
        $filtered = apply_filters(self::FILTER, $this->stored($now));

        return $this->validate(is_array($filtered) ? $filtered : [], $now);
    }

    /**
     * The stored option alone, validated, before the filter: what the admin
     * form controls, as opposed to what site code adds on top.
     *
     * @return array<string, int>
     */
    public function storedExpiries(): array
    {
        return $this->stored(time());
    }

    /**
     * Persist the shadow set from the admin form. A checked pack that is
     * already validly shadowed keeps its expiry (saving the form again does
     * not silently extend an observation window); a checked pack that is not
     * shadowed yet gets $ttl seconds from now, clamped to {@see MAX_TTL}; an
     * unchecked pack is removed. Entries that had already lapsed are dropped
     * too and reported as expired, so the caller can audit every removal by
     * its actual cause.
     *
     * @param list<string> $checked
     * @return array{enabled: array<string, int>, disabled: list<string>, expired: array<string, ?int>}
     */
    public function apply(array $checked, int $ttl): array
    {
        $now = time();
        $raw = get_option(self::OPTION, []);
        $before = $this->validate(is_array($raw) ? $raw : [], $now);
        $expiry = $now + min($ttl, self::MAX_TTL);

        $after = [];
        $enabled = [];
        foreach ($checked as $name) {
            if (isset($before[$name])) {
                $after[$name] = $before[$name];
                continue;
            }
            if ($expiry > $now) {
                $after[$name] = $expiry;
                $enabled[$name] = $expiry;
            }
        }

        update_option(self::OPTION, $after, false);

        return [
            'enabled' => $enabled,
            'disabled' => array_values(array_diff(array_keys($before), array_keys($after))),
            'expired' => $this->lapsed(is_array($raw) ? $raw : [], $before),
        ];
    }

    /**
     * The hourly cron half of expiry: drop every stored entry that no longer
     * shadows anything and audit each one, so the log shows when enforcement
     * resumed and not only when observation began. A pack a filter shadows is
     * not stored and so is never swept — the filter's own validation bounds it.
     */
    public function sweep(AdminChangeRecorder $changes): void
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw)) {
            return;
        }

        $valid = $this->validate($raw, time());
        $lapsed = $this->lapsed($raw, $valid);
        if ($lapsed === [] && count($raw) === count($valid)) {
            return;
        }

        update_option(self::OPTION, $valid, false);

        foreach ($lapsed as $name => $stamp) {
            $changes->shadowExpired($name, $stamp);
        }
    }

    /**
     * One-time upgrade of the pre-expiry option shape (a plain list of pack
     * names) into name => expiry, giving each pack the full {@see MAX_TTL}
     * from now: an operator mid-observation keeps the week the roadmap
     * promised instead of being switched to enforcement by an upgrade. A map
     * is already migrated and a non-array is left for {@see packs()} to fail
     * closed on; both are untouched.
     */
    public function migrateLegacy(): void
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            return;
        }

        $expiry = time() + self::MAX_TTL;
        $map = [];
        foreach ($raw as $name) {
            if (is_string($name) && $name !== '') {
                $map[$name] = $expiry;
            }
        }

        update_option(self::OPTION, $map, false);
    }

    /**
     * The stored option, validated but not yet filtered.
     *
     * @return array<string, int>
     */
    private function stored(int $now): array
    {
        $raw = get_option(self::OPTION, []);

        return $this->validate(is_array($raw) ? $raw : [], $now);
    }

    /**
     * Keep only name => int entries whose expiry is in the future and within
     * {@see MAX_TTL} of now. Everything else is dropped, never coerced: a
     * legacy list entry, a numeric string, a lapsed or far-future stamp all
     * mean "not shadowed".
     *
     * @param array<mixed> $entries
     * @return array<string, int>
     */
    private function validate(array $entries, int $now): array
    {
        $clean = [];
        foreach ($entries as $name => $expiry) {
            if (!is_string($name) || $name === '' || !is_int($expiry)) {
                continue;
            }
            if ($expiry > $now && $expiry <= $now + self::MAX_TTL) {
                $clean[$name] = $expiry;
            }
        }

        return $clean;
    }

    /**
     * The entries in the raw option that validation dropped, name => the
     * stamp that was stored (null when there was no int to show). A legacy
     * list entry is named by its value, a map entry by its key.
     *
     * @param array<mixed>       $raw
     * @param array<string, int> $valid
     * @return array<string, ?int>
     */
    private function lapsed(array $raw, array $valid): array
    {
        $lapsed = [];
        foreach ($raw as $key => $value) {
            $name = is_string($key) ? $key : (is_string($value) ? $value : '');
            if ($name !== '' && !isset($valid[$name]) && !array_key_exists($name, $lapsed)) {
                $lapsed[$name] = is_int($value) ? $value : null;
            }
        }

        return $lapsed;
    }
}
