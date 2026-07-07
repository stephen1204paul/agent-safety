<?php

/**
 * Plugin Name:       Agent Safety
 * Description:       Governed safety and audit layer for AI agent tool calls — verb-tier gating, capability packs, human approval for irreversible actions, and a compliance-grade audit trail. WooCommerce is supported via an integration module.
 * Version:           0.1.0-dev
 * Requires PHP:      8.1
 * Requires at least: 6.9
 * License:           GPL-2.0-or-later
 * Text Domain:       agent-safety
 *
 * Thin host (D19): wires the security core (specflux/agent-safety-core) into
 * WordPress hooks. All decision logic lives in the package under ../src. The
 * plugin itself is WordPress-general; ALL WooCommerce-specific wiring lives in
 * Integrations\Woo\WooIntegration, registered ONLY when WooCommerce is active.
 */

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin;

use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;
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
use Specflux\AgentSafety\Plugin\Identity\ApplicationPasswordIdentity;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Identity\UserRoleIdentity;
use Specflux\AgentSafety\Plugin\Integrations\Woo\VerbMapper;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooIntegration;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;

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
// before an integration (e.g. WooCommerce) registers its abilities.
if (class_exists(Gate::class)) {
    global $wpdb;

    // Identity chain (SPEC seam 4): application passwords and users/roles apply
    // on ANY WordPress site; an integration appends its own provider below.
    $agsafe_identity = new IdentityChain([
        new ApplicationPasswordIdentity(),
        new UserRoleIdentity(),
    ]);

    // Verb catalog + elevation rules (SPEC seams 1-2) and the pack/namespace
    // contributions (SPEC seams 3/6) start empty — a Woo-less site classifies
    // nothing, gates nothing beyond the generic fail-closed default pack, and
    // governs no ability namespace.
    $agsafe_catalog = new VerbCatalog();
    $agsafe_elevation_rules = [];
    $agsafe_extra_packs = [];
    $agsafe_governed_namespaces = [];

    if (WooIntegration::available()) {
        $agsafe_woo = WooIntegration::register($agsafe_catalog, $agsafe_identity, isset($wpdb) ? $wpdb : null);
        $agsafe_elevation_rules = $agsafe_woo['elevationRules'];
        $agsafe_extra_packs = $agsafe_woo['packs'];
        $agsafe_governed_namespaces = $agsafe_woo['governedNamespaces'];
    }

    // Site owners (or other plugins) can widen the governed namespace list
    // without an integration module of their own.
    $agsafe_governed_namespaces = function_exists('apply_filters')
        ? apply_filters('agent_safety_governed_namespaces', $agsafe_governed_namespaces)
        : $agsafe_governed_namespaces;

    RequestContext::configure($agsafe_identity);

    $agsafe_classifier = new TierClassifier($agsafe_catalog, $agsafe_elevation_rules);
    $agsafe_gate = new Gate($agsafe_classifier);
    $agsafe_packs = new PackResolver($agsafe_extra_packs);
    $agsafe_sink = isset($wpdb) ? new WpdbAuditSink($wpdb) : null;
    $agsafe_approvals = isset($wpdb) ? new WpdbApprovalStore($wpdb) : null;

    // Shared decision-recorder: BOTH gate seams audit verdicts + persist pending
    // approvals through this one object, so they can never diverge (SPEC §4/§5).
    $agsafe_recorder = new DecisionRecorder($agsafe_sink, $agsafe_approvals);

    // Primary seam on the shipping stack (WP core Abilities API; adapter-version-independent).
    // Audits the verdicts that never execute (denied / approval-pending) and owns the
    // reserve→finalize approval lifecycle (SPEC §4). Inert no-op for any ability
    // outside $agsafe_governed_namespaces (SPEC seam 6).
    (new AbilityPermissionGate($agsafe_gate, $agsafe_packs, $agsafe_recorder, $agsafe_approvals, $agsafe_governed_namespaces))->register();

    // Forward-compat seam: fires only if a mcp-adapter >= 0.5.0 is the loaded copy.
    // Shares the same PackResolver + DecisionRecorder as the live seam so both honour
    // identical bindings and record decisions identically. The verb mapper assumes
    // WooCommerce's "namespace-resource-action" tool-naming convention, so this seam
    // is only meaningful (and only wired up) when WooCommerce is the active integration.
    if (WooIntegration::available()) {
        (new PreToolCallGate($agsafe_gate, new VerbMapper(), $agsafe_packs, $agsafe_recorder))->register();
    }

    // Audit every ability that actually executes (SPEC §5) — successes and failures.
    if ($agsafe_sink !== null) {
        (new AbilityAuditLog($agsafe_sink, $agsafe_classifier, $agsafe_packs, $agsafe_governed_namespaces))->register();

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
            McpRequestAuditHandler::configure($agsafe_sink, $agsafe_packs, $agsafe_classifier);
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

    // Capability-pack admin (Tools → Agent Capability Packs): bind each identity
    // the configured providers expose to a pack from the catalog (SPEC §3).
    (new CapabilityPacksPage($agsafe_packs, $agsafe_identity))->register();
}
