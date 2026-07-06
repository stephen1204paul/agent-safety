<?php

/**
 * Plugin Name:       Agent Safety
 * Description:       Governed safety and audit layer for AI agent tool calls — verb-tier gating, capability packs, human approval for irreversible actions, and a compliance-grade audit trail. WooCommerce is supported via a capability pack.
 * Version:           0.1.0-dev
 * Requires PHP:      8.1
 * Requires at least: 6.9
 * License:           GPL-2.0-or-later
 * Text Domain:       agent-safety
 *
 * Thin host (D19): wires the security core (specflux/agent-safety-core) into
 * WordPress hooks. All decision logic lives in the package under ../src.
 */

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin;

use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Plugin\Admin\AuditLogPage;
use Specflux\AgentSafety\Plugin\Admin\CapabilityPacksPage;
use Specflux\AgentSafety\Plugin\Admin\PendingActionsPage;
use Specflux\AgentSafety\Plugin\Audit\AuditReader;
use Specflux\AgentSafety\Plugin\Audit\WpdbApprovalStore;
use Specflux\AgentSafety\Plugin\Audit\WpdbAuditSink;
use Specflux\AgentSafety\Plugin\Hooks\AbilityAuditLog;
use Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate;
use Specflux\AgentSafety\Plugin\Hooks\McpRequestAuditHandler;
use Specflux\AgentSafety\Plugin\Hooks\PreToolCallGate;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\VerbMapper;

if (!defined('ABSPATH')) {
    exit;
}

// Bundled autoloader: `composer install` in this dir copies the core package
// into vendor/ and wires PSR-4 for both the core and the plugin's own classes.
$agsafe_autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($agsafe_autoload)) {
    require_once $agsafe_autoload;
}

// Register at plugin-load time (NOT on init): the ability seam must be wired
// before WooCommerce registers its abilities.
if (class_exists(Gate::class)) {
    global $wpdb;

    $agsafe_gate = new Gate();
    $agsafe_packs = new PackResolver();
    $agsafe_sink = isset($wpdb) ? new WpdbAuditSink($wpdb) : null;
    $agsafe_approvals = isset($wpdb) ? new WpdbApprovalStore($wpdb) : null;

    // Shared decision-recorder: BOTH gate seams audit verdicts + persist pending
    // approvals through this one object, so they can never diverge (SPEC §4/§5).
    $agsafe_recorder = new DecisionRecorder($agsafe_sink, $agsafe_approvals);

    // Primary seam on the shipping stack (WP core Abilities API; adapter-version-independent).
    // Audits the verdicts that never execute (denied / approval-pending) and owns the
    // reserve→finalize approval lifecycle (SPEC §4).
    (new AbilityPermissionGate($agsafe_gate, $agsafe_packs, $agsafe_recorder, $agsafe_approvals))->register();

    // Forward-compat seam: fires only if a mcp-adapter >= 0.5.0 is the loaded copy.
    // Shares the same PackResolver + DecisionRecorder as the live seam so both honour
    // identical bindings and record decisions identically.
    (new PreToolCallGate($agsafe_gate, new VerbMapper(), $agsafe_packs, $agsafe_recorder))->register();

    // Audit every ability that actually executes (SPEC §5) — successes and failures.
    if ($agsafe_sink !== null) {
        (new AbilityAuditLog($agsafe_sink, new TierClassifier(), $agsafe_packs))->register();

        // wp-admin viewer + CSV export (Tools → Agent Audit Log).
        (new AuditLogPage(new AuditReader($wpdb)))->register();

        // Forward-compat observability-based audit consumer: proves a plugin can
        // build the audit trail on mcp-adapter's public `McpObservabilityHandlerInterface`
        // alone (upstream issue #176 discussion), independent of and in addition
        // to the Abilities-API seams above. Dormant no-op unless an mcp-adapter
        // carrying that interface is the loaded copy — `interface_exists()` here
        // both checks AND (via its default $autoload) resolves that without ever
        // risking a fatal on sites without the adapter, exactly like the
        // `class_exists(Gate::class)` guard this whole block already lives in.
        if (interface_exists(\WP\MCP\Infrastructure\Observability\Contracts\McpObservabilityHandlerInterface::class)) {
            McpRequestAuditHandler::configure($agsafe_sink, $agsafe_packs, new TierClassifier());
            (new McpRequestAuditHandler())->register();

            add_filter('mcp_adapter_default_server_config', static function (array $config): array {
                $config['observability_handler'] = McpRequestAuditHandler::class;

                return $config;
            });
        }
    }

    // Human approval queue (Tools → Pending Agent Actions): approve/reject blocked
    // irreversible actions, minting single-use tokens (SPEC §4).
    if ($agsafe_approvals !== null) {
        (new PendingActionsPage($agsafe_approvals, $agsafe_sink, $agsafe_packs))->register();
    }

    // Capability-pack admin (Tools → Agent Capability Packs): bind each WC API key
    // to a pack from the catalog (SPEC §3). No-op without $wpdb (key listing).
    if (isset($wpdb)) {
        (new CapabilityPacksPage($agsafe_packs, $wpdb))->register();
    }
}
