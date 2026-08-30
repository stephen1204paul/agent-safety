<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin;

use Specflux\AgentSafety\Plugin\Api\Approvals;
use Specflux\AgentSafety\Plugin\Api\Grants;

/**
 * The plugin's service locator, built ONCE by the bootstrap after every seam
 * is wired, and reached from anywhere (other plugins, wp-admin, site code)
 * through the global {@see agent_safety()} helper.
 *
 * Deliberately minimal: it exposes only the services with an external
 * contract. Internal collaborators (gate, classifier, recorder…) stay
 * constructor-injected in the bootstrap closure — handing those out would
 * invite bypassing the seams.
 */
final class Container
{
    private static ?self $instance = null;

    private function __construct(
        private readonly ?Approvals $approvals,
        private readonly ?Grants $grants = null,
    ) {
    }

    /** Called once by the plugin bootstrap on plugins_loaded. Later calls overwrite. */
    public static function init(?Approvals $approvals, ?Grants $grants = null): void
    {
        self::$instance = new self($approvals, $grants);
    }

    /**
     * The container, or null before `plugins_loaded` (or when the core lib is
     * missing/broken and the bootstrap bailed early). Callers MUST null-check:
     * null means "Agent Safety isn't wired yet", never "throw".
     */
    public static function instance(): ?self
    {
        return self::$instance;
    }

    /**
     * The programmatic approvals API (AS-10), or null on the pathological
     * no-database path. Feature-detect THIS rather than assuming: a consumer
     * must handle null by falling back to the wp-admin Pending Agent Actions
     * screen.
     */
    public function approvals(): ?Approvals
    {
        return $this->approvals;
    }

    /**
     * The pre-approval grants API (AS-12), or null on the pathological
     * no-database path. Feature-detect THIS the same way as approvals(): null
     * means the service is not available, and a consumer must fall back to
     * ordinary per-action approvals rather than assuming grants exist.
     *
     * Note that a non-null service is NOT the same as the feature being on —
     * `agent_safety_enable_grants` defaults to false, and {@see Grants::issue()}
     * refuses while it is off. Check {@see Grants::enabled()} if you need to
     * tell a user why nothing was issued.
     */
    public function grants(): ?Grants
    {
        return $this->grants;
    }

    /** @internal tests only. */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
