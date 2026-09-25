<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Admin;

use Specflux\AgentSafety\Plugin\Support\ShadowMode;

/**
 * AS-7 §3.4 item 12: "the environment type is shown on the AS admin screens" —
 * one rendering helper shared by every AS admin page, so the label reads
 * identically wherever it appears rather than drifting per screen. Originally
 * only {@see CapabilityPacksPage} showed this (alongside its Rebind form,
 * which stays page-specific); {@see AuditLogPage} and {@see PendingActionsPage}
 * reuse this same helper for the label only.
 */
final class EnvironmentLabel
{
    /** Echoes the "Environment" heading and "Environment type: ..." paragraph. */
    public static function render(ShadowMode $shadow): void
    {
        echo '<h2>' . esc_html__('Environment', 'agent-safety') . '</h2>';
        echo '<p>' . esc_html(sprintf(
            /* translators: %s the WordPress environment type (production, staging, ...) */
            __('Environment type: %s', 'agent-safety'),
            $shadow->isProduction() ? __('production', 'agent-safety') : __('non-production', 'agent-safety')
        )) . '</p>';
    }
}
