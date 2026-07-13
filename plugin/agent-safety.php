<?php

/**
 * Plugin Name:       Agent Safety
 * Description:       Governed safety and audit layer for AI agent tool calls — verb-tier gating, capability packs, human approval for irreversible actions, and a compliance-grade audit trail. WooCommerce is supported via an integration module.
 * Version:           0.1.0
 * Requires PHP:      8.1
 * Requires at least: 6.9
 * License:           GPL-2.0-or-later
 * Text Domain:       agent-safety
 *
 * Thin host: wires the security core (specflux/agent-safety-core) into
 * WordPress hooks. All decision logic lives in the package under ../src. The
 * plugin itself is WordPress-general; ALL WooCommerce-specific wiring lives in
 * Integrations\Woo\WooIntegration, registered ONLY when WooCommerce is active.
 */

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin;

use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Policy\Tier;
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
use Specflux\AgentSafety\Plugin\Hooks\ToolCallResultRedactor;
use Specflux\AgentSafety\Plugin\Identity\ApplicationPasswordIdentity;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Identity\UserRoleIdentity;
use Specflux\AgentSafety\Plugin\Integrations\Woo\VerbMapper;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WooIntegration;
use Specflux\AgentSafety\Plugin\Support\ApprovalSweep;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\Schema;

if (!defined('ABSPATH')) {
    exit;
}

// Bundled autoloader: `composer install` in this dir copies the core package
// into vendor/ and wires PSR-4 for both the core and the plugin's own classes.
$agsafe_autoload = __DIR__ . '/vendor/autoload.php';
if (is_readable($agsafe_autoload)) {
    require_once $agsafe_autoload;
}

// Registered UNCONDITIONALLY (not inside the class_exists(Gate::class) guard
// below): WordPress calls activation/deactivation hooks by including this
// file fresh and checking what got registered THAT load. If registration
// itself depended on the autoloader having succeeded, activating on a site
// where `composer install` was never run in plugin/ would silently register
// nothing and no table/cron would ever be created — the callbacks below
// guard internally instead, so a broken autoloader fails safe, not silent.
register_activation_hook(__FILE__, __NAMESPACE__ . '\\activate_agent_safety');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\deactivate_agent_safety');

/** Create/upgrade both tables and schedule the approval sweep. */
function activate_agent_safety(): void
{
    $agsafe_autoload = __DIR__ . '/vendor/autoload.php';
    if (is_readable($agsafe_autoload)) {
        require_once $agsafe_autoload;
    }

    if (!class_exists(Schema::class) || !class_exists(ApprovalSweep::class)) {
        return;
    }

    global $wpdb;
    if (isset($wpdb)) {
        Schema::install($wpdb);
    }

    ApprovalSweep::activate();
}

/** Stop the approval sweep. Table data is intentionally kept — see uninstall.php. */
function deactivate_agent_safety(): void
{
    if (class_exists(ApprovalSweep::class)) {
        ApprovalSweep::deactivate();
    }
}

// Register at plugin-load time (NOT on init): the ability seam must be wired
// before an integration (e.g. WooCommerce) registers its abilities.
if (class_exists(Gate::class)) {
    global $wpdb;

    // Cheap version check on every wp-admin load: an admin visiting after a
    // plugin UPDATE (not a fresh activation) still gets the table upgraded,
    // since register_activation_hook only fires on activate, never on update.
    if (isset($wpdb)) {
        add_action('admin_init', static function () use ($wpdb): void {
            Schema::maybeUpgrade($wpdb);
        });
    }

    // Identity chain: application passwords and users/roles apply
    // on ANY WordPress site; an integration appends its own provider below.
    $agsafe_identity = new IdentityChain([
        new ApplicationPasswordIdentity(),
        new UserRoleIdentity(),
    ]);

    // Verb catalog + elevation rules and the pack/namespace
    // contributions start empty — a Woo-less site classifies
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

    // Companion seam to the namespace filter: governing a namespace without
    // mapping its verbs would deny every call in it as unknown_verb (the Gate
    // fails closed on unclassified verbs by design). Anyone widening
    // agent_safety_governed_namespaces MUST also map those verbs to tiers here.
    // Values may be Tier instances or their backing ints (0 = Reversible,
    // 1 = SideEffecting, 2 = Irreversible); anything else is dropped, keeping
    // the fail-closed default for that verb. Example:
    //   ['my-plugin/thing-list' => 0, 'my-plugin/thing-delete' => 2]
    // Found by live smoke test 2026-07-07: without this seam the namespace
    // filter's advertised extensibility was unreachable outside WooIntegration.
    if (function_exists('apply_filters')) {
        $agsafe_extra_verbs = [];
        foreach ((array) apply_filters('agent_safety_verb_map', []) as $agsafe_verb => $agsafe_tier) {
            if ($agsafe_tier instanceof Tier) {
                $agsafe_extra_verbs[$agsafe_verb] = $agsafe_tier;
            } elseif (is_int($agsafe_tier) && Tier::tryFrom($agsafe_tier) !== null) {
                $agsafe_extra_verbs[$agsafe_verb] = Tier::from($agsafe_tier);
            }
        }
        if ($agsafe_extra_verbs !== []) {
            $agsafe_catalog->register($agsafe_extra_verbs);
        }
    }

    RequestContext::configure($agsafe_identity);

    $agsafe_classifier = new TierClassifier($agsafe_catalog, $agsafe_elevation_rules);
    $agsafe_gate = new Gate($agsafe_classifier);
    $agsafe_packs = new PackResolver($agsafe_extra_packs);
    $agsafe_sink = isset($wpdb) ? new WpdbAuditSink($wpdb) : null;
    $agsafe_approvals = isset($wpdb) ? new WpdbApprovalStore($wpdb) : null;

    // Shared decision-recorder: BOTH gate seams audit verdicts + persist pending
    // approvals through this one object, so they can never diverge.
    $agsafe_recorder = new DecisionRecorder($agsafe_sink, $agsafe_approvals);

    // Shared rate-limit gate (backlog #16): BOTH seams enforce a pack's
    // calls_per_minute/calls_per_hour caps through this one object, for the same
    // reason $agsafe_recorder is shared — identical enforcement no matter which
    // seam intercepts a call first.
    $agsafe_rate_limits = new RateLimitGate();

    // Primary seam on the shipping stack (WP core Abilities API; adapter-version-independent).
    // Audits the verdicts that never execute (denied / approval-pending) and owns the
    // reserve→finalize approval lifecycle. Inert no-op for any ability
    // outside $agsafe_governed_namespaces.
    (new AbilityPermissionGate($agsafe_gate, $agsafe_packs, $agsafe_recorder, $agsafe_approvals, $agsafe_governed_namespaces, $agsafe_rate_limits))->register();

    // Forward-compat seam: fires only if a mcp-adapter >= 0.5.0 is the loaded copy.
    // Shares the same PackResolver + DecisionRecorder as the live seam so both honour
    // identical bindings and record decisions identically. The verb mapper assumes
    // WooCommerce's "namespace-resource-action" tool-naming convention, so this seam
    // is only meaningful (and only wired up) when WooCommerce is the active integration.
    if (WooIntegration::available()) {
        (new PreToolCallGate($agsafe_gate, new VerbMapper(), $agsafe_packs, $agsafe_recorder, $agsafe_rate_limits))->register();
    }

    // Read-path PII redaction (backlog #11): masks the payload RETURNED TO THE
    // AGENT for a governed, executed tool call whose pack redacts PII. Unlike
    // PreToolCallGate, this has no Woo-specific tool-naming dependency (it reads
    // the real ability id off the adapter's own $mcp_tool observability context),
    // so it is wired for ANY governed namespace, not gated on WooIntegration.
    // Dormant no-op unless a real mcp-adapter carrying this filter is loaded
    // (fires only >= 0.5.0) and $agsafe_governed_namespaces is non-empty.
    (new ToolCallResultRedactor($agsafe_packs, $agsafe_governed_namespaces))->register();

    // Audit every ability that actually executes — successes and failures.
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
    // irreversible actions, minting single-use tokens.
    if ($agsafe_approvals !== null) {
        (new PendingActionsPage($agsafe_approvals, $agsafe_sink, $agsafe_packs))->register();

        // Backlog control for the table above: hourly sweep of expired/orphaned
        // approval rows (see WpdbApprovalStore::deleteExpired()). The schedule
        // itself is set up on activation; this just wires the callback.
        add_action(ApprovalSweep::HOOK, static function () use ($agsafe_approvals): void {
            ApprovalSweep::run($agsafe_approvals);
        });
    }

    // Capability-pack admin (Tools → Agent Capability Packs): bind each identity
    // the configured providers expose to a pack from the catalog.
    (new CapabilityPacksPage($agsafe_packs, $agsafe_identity))->register();
}
