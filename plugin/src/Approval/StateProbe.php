<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Approval;

/**
 * A host-side probe of one governed object's mutable state, used to detect
 * whether the target of a pending or approved Approval changed after a human
 * saw it (AS-6, the "state fingerprint"). Lives in the plugin, not the core
 * library, because every real probe calls WordPress/WooCommerce functions.
 *
 * Contract: return the smallest fact set that answers "did this change?" —
 * for an entity, its modified timestamp plus its status is enough. Return
 * null when the target no longer exists (deleted, never existed) — the
 * pipeline treats that identically to a thrown exception: the call is
 * refused `state_unverifiable` rather than silently treated as unchanged.
 */
interface StateProbe
{
    /**
     * @param array<string, mixed> $args The verb's call arguments.
     * @return array<string, mixed>|null Probe facts, or null if the target is gone.
     */
    public function read(string $verb, array $args): ?array;
}
