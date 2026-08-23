<?php

/**
 * Global service locator for Agent Safety consumers. Defined in the global
 * namespace so ANY other plugin can call it without knowing this plugin's
 * namespace; guarded by function_exists, so an older/newer copy of the file
 * loading twice is a no-op.
 *
 * Usage (feature-detected — absent entirely on Agent Safety < 0.3):
 *
 *   if (function_exists('agent_safety')
 *       && ($container = agent_safety()) !== null
 *       && ($approvals = $container->approvals()) !== null) {
 *       $approvals->approve($approvalId, get_current_user_id());
 *   }
 */

declare(strict_types=1);

use Specflux\AgentSafety\Plugin\Container;

if (!function_exists('agent_safety')) {
    /**
     * The Agent Safety service container, or null before `plugins_loaded` —
     * or when the plugin's autoloader is broken (the same fail-safe posture
     * as the rest of the bootstrap: degraded, never fatal).
     *
     * @return Container|null
     */
    function agent_safety(): ?Container
    {
        if (!class_exists(Container::class)) {
            return null;
        }

        return Container::instance();
    }
}
