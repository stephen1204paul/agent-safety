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

    public function isShadow(string $packName): bool
    {
        return in_array($packName, $this->packs(), true);
    }

    /**
     * The pack names currently in log-only observation.
     *
     * @return list<string>
     */
    public function packs(): array
    {
        $stored = get_option(self::OPTION, []);
        $packs = apply_filters('agent_safety_shadow_packs', is_array($stored) ? $stored : []);
        if (!is_array($packs)) {
            return [];
        }

        $clean = [];
        foreach ($packs as $name) {
            if (is_string($name) && $name !== '') {
                $clean[] = $name;
            }
        }

        return $clean;
    }
}
