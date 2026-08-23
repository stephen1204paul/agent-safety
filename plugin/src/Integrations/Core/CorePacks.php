<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Core;

use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Packs\ArgumentCap;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Core-flavoured Capability Packs, registered into the core
 * {@see \Specflux\AgentSafety\Packs\PackRegistry} by {@see CoreIntegration::register()}
 * on top of the builtins. Same shape and safety posture as
 * {@see \Specflux\AgentSafety\Plugin\Integrations\Woo\WooPacks}:
 *
 *   - site-readonly     — the six read verbs (three live + three proposed)
 *                         only, AND every write class hard-walled via
 *                         denyClass: belt and braces, so a read-looking verb
 *                         that writes still can't slip through.
 *   - content-editor    — reads plus core/manage-content; every elevated
 *                         (Tier-2) call — publishing, bulk delete/trash —
 *                         needs human approval first.
 *   - site-admin-agent  — all nine verbs for a trusted admin-bound agent.
 *                         Every Tier-2 verb is approval-gated, and bulk
 *                         content operations are bounded at 25 items per
 *                         call via an ArgumentCap on the `ids` argument.
 *
 * The cap's verbs pattern is deliberately the exact manage-content id, NOT a
 * `core/manage-*` glob: ArgumentCapPolicy fails closed when argPath is
 * missing or malformed, so a glob spanning manage-settings/manage-users would
 * DENY every one of those calls outright (they carry no `ids` list). The
 * flip-side of fail-closed: a manage-content call WITHOUT ids (e.g. create)
 * also denies under this preset — intended, documented here, and pinned by
 * test_site_admin_agent_caps_bulk_items_at_25.
 */
final class CorePacks
{
    /** @return list<Pack> */
    public static function all(): array
    {
        $readVerbs = [
            CoreVerbCatalog::GET_SITE_INFO,
            CoreVerbCatalog::GET_ENVIRONMENT_INFO,
            CoreVerbCatalog::GET_USER_INFO,
            CoreVerbCatalog::READ_SETTINGS,
            CoreVerbCatalog::READ_CONTENT,
            CoreVerbCatalog::READ_USERS,
        ];

        return [
            new Pack(
                name: 'site-readonly',
                allow: $readVerbs,
                denyClass: [Tier::SideEffecting->classSlug(), Tier::Irreversible->classSlug()],
            ),
            new Pack(
                name: 'content-editor',
                allow: [...$readVerbs, CoreVerbCatalog::MANAGE_CONTENT],
                approvalByClass: [Tier::Irreversible->classSlug() => true],
            ),
            new Pack(
                name: 'site-admin-agent',
                allow: [
                    ...$readVerbs,
                    CoreVerbCatalog::MANAGE_CONTENT,
                    CoreVerbCatalog::MANAGE_SETTINGS,
                    CoreVerbCatalog::MANAGE_USERS,
                ],
                approvalByClass: [Tier::Irreversible->classSlug() => true],
                argumentCaps: [
                    new ArgumentCap(
                        id: 'core-bulk-items',
                        verbs: CoreVerbCatalog::MANAGE_CONTENT,
                        argPath: 'ids',
                        maxItemsPerCall: 25,
                    ),
                ],
            ),
        ];
    }
}
