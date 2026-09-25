<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Self;

use Specflux\AgentSafety\Audit\AuditSink;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\VerbCatalog;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Support\PauseSwitch;
use Specflux\AgentSafety\Plugin\Verdict\VerdictPipeline;

/**
 * AS-8 (§3.5): the plugin's OWN built-in integration — not a third-party
 * capability pack module like Core/Woo, but the same registration shape, so
 * the bootstrap can treat it uniformly. Registered UNCONDITIONALLY, like
 * {@see \Specflux\AgentSafety\Plugin\Integrations\Core\CoreIntegration}: the
 * `agent-safety/check-approval` ability exists on every site this plugin runs
 * on, independent of WooCommerce.
 *
 * Contributes the `agent-safety/` namespace as governed (so the ability goes
 * through the ordinary permission-gate seam) and catalogues its one verb at a
 * FIXED tier 0 — fixed in the sense that nothing in this file makes the tier
 * unconditional; the actual "no pack can change it" guarantee is the named
 * exemption inside {@see VerdictPipeline::judge()}, not this catalog entry.
 * Any OTHER `agent-safety/*` verb is deliberately left unclassified, so it
 * denies `unknown_verb` like any ungoverned write — the exemption is this one
 * verb, never the namespace.
 */
final class SelfIntegration
{
    /** The ability category this module registers on `wp_abilities_api_categories_init`. */
    public const CATEGORY = 'agent-safety';

    public static function available(): bool
    {
        return true;
    }

    /**
     * @return array{elevationRules: list<never>, packs: list<never>, governedNamespaces: list<string>, stateProbes: array<string, never>}
     */
    public static function register(VerbCatalog $catalog): array
    {
        $catalog->register([VerdictPipeline::CHECK_APPROVAL_VERB => Tier::Reversible]);

        return [
            'elevationRules' => [],
            'packs' => [],
            'governedNamespaces' => ['agent-safety/'],
            'stateProbes' => [],
        ];
    }

    /**
     * Wire the ability + its category onto the real Abilities API hooks
     * (§3.5 item 8: always registered while the plugin is active, no
     * disabling filter — unconditional `add_action`, exactly like
     * {@see \Specflux\AgentSafety\Plugin\Hooks\AbilityAuditLog::register()}
     * being wired regardless of feature flags).
     */
    public static function registerAbility(?WpdbApprovalStore $approvals, ?AuditSink $sink, ?PauseSwitch $pause): void
    {
        $ability = new CheckApprovalAbility($approvals, $sink, $pause);

        add_action('wp_abilities_api_categories_init', static function (): void {
            wp_register_ability_category(self::CATEGORY, [
                'label' => __('Agent Safety', 'agent-safety'),
                'description' => __('Abilities added by the Agent Safety plugin.', 'agent-safety'),
            ]);
        });

        add_action('wp_abilities_api_init', static function () use ($ability): void {
            wp_register_ability(VerdictPipeline::CHECK_APPROVAL_VERB, [
                'label' => __('Check Approval', 'agent-safety'),
                'description' => __(
                    'Check the status of an approval_required request by its approval_id. Returns the current status, what to do next, and (once resolved) whether a human or a standing grant resolved it.',
                    'agent-safety'
                ),
                'category' => self::CATEGORY,
                'permission_callback' => [$ability, 'permissionCallback'],
                'execute_callback' => [$ability, 'execute'],
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'approval_id' => [
                            'type' => 'string',
                            'description' => __('The approval_id from an earlier approval_required response.', 'agent-safety'),
                        ],
                    ],
                    'required' => ['approval_id'],
                ],
                'meta' => [
                    'mcp' => ['public' => true],
                    'annotations' => [
                        'readonly' => true,
                        'instructions' => __(
                            'Poll no faster than every 30 seconds; back off to every 5 minutes; stop polling at pending_expires_at. Only status "approved" means retry the original call, with exactly the original arguments. Do not tell the user the action was approved before that.',
                            'agent-safety'
                        ),
                    ],
                ],
            ]);
        });
    }
}
