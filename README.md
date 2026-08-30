# Agent Safety

A governed safety and audit layer for AI-agent tool calls in WordPress — verb-tier gating, capability packs, human approval, and a hash-chained audit trail.

[![CI](https://github.com/stephen1204paul/agent-safety/actions/workflows/ci.yml/badge.svg)](https://github.com/stephen1204paul/agent-safety/actions/workflows/ci.yml)

## Why

AI agents are starting to call WordPress the same way plugins do — through the Abilities API, and through MCP servers built on [WordPress/mcp-adapter](https://github.com/WordPress/mcp-adapter). That's a new kind of caller: not a human clicking through wp-admin, and not a plugin whose code you audited once and trust to behave the same way every time. An agent decides *at runtime* which tool to call and with what arguments, and it can decide wrong — delete the wrong product, refund the wrong order, cancel a fulfillment — with no confirmation step and no record of why.

Most integrations answer this by trusting the tool's own metadata (a `readOnlyHint` annotation, a "safe" label) or by trusting the credential (an application password can do whatever the underlying REST route allows). Neither is a policy. Agent Safety sits between the agent and the site and makes that policy explicit: every governed tool call is classified, checked against a capability pack bound to the calling identity, and written to an audit log — before it runs.

It ships WordPress-general. WooCommerce is the flagship integration, wired in only when WooCommerce is active, not a dependency.

## How it works

Every governed call goes through the same pipeline, regardless of whether it arrived through the Abilities API directly or through an MCP tool call:

```
 agent / MCP client
        │
        ▼
 ┌─────────────────┐     verb → tier           ┌──────────────────┐
 │  Identity Chain  │     (fail-closed on       │  Tier Classifier │
 │  app password /  │      unknown verbs)       │  0 read          │
 │  user or role /  │                           │  1 side-effecting│
 │  WC API key      │                           │  2 irreversible  │
 └────────┬─────────┘                           └─────────┬────────┘
          │ identity                                       │ tier
          ▼                                                ▼
                        ┌───────────────────────┐
                        │         Gate          │
                        │ pack allow-list?       │
                        │ deny class?            │
                        │ rate limit?            │
                        │ approval required?     │
                        └───────────┬────────────┘
                     allow │        │ deny            │ needs approval
                            ▼        ▼                  ▼
                        execute   refuse         queue in Pending
                            │        │            Actions, wait for
                            │        │            human approve/reject
                            ▼        ▼                  │
                        ┌───────────────────────────────┘
                        │  Hash-chained Audit Log
                        │  (every decision + outcome)
                        └───────────────────────
```

**Verb-tier classification.** Every governed action maps to a verb (`orders/update`, `products/delete`, `core/read-post`, …), and every verb maps to a tier: `Reversible` (read), `SideEffecting` (write, reversible), or `Irreversible` (delete, refund, cancel — anything that can't be trivially undone). An unrecognized verb fails closed — it's treated as `Irreversible`, not as safe by default. A tool's own `readOnlyHint` or `destructiveHint` annotation can only *tighten* the classification, never loosen it; a tool that reports itself read-only but is classified as a write is still gated as a write.

**Capability packs.** A pack is a named, scoped view of the verb catalog: an allow-list, a hard-denied tier class, a per-tier approval requirement, an optional PII-redaction policy, and optional rate limits (`calls_per_minute` / `calls_per_hour`). Packs are bound to identities, not roles alone — a WordPress application password, a specific user or role, or, when WooCommerce is active, a WooCommerce REST API key. A credential with no explicit binding falls back to a safe default pack rather than inheriting full access.

**Human approval.** A call classified as `Irreversible` (and not hard-denied outright by its pack) doesn't execute — it's queued under **Tools → Pending Agent Actions**. A human approves or rejects it. Approval mints a time-limited, single-claim grant: the same caller can simply retry the action, or a delegate can present the grant's token explicitly. An hourly sweep expires anything left pending too long.

**Hash-chained audit log.** Every gate decision and every executed action is appended to a tamper-evident, hash-chained log (`agsafe_` prefixed tables), viewable and CSV-exportable under **Tools → Agent Audit Log**. The chain is re-verified on load, so a modified or deleted row is detectable, not just theoretically possible.

**Read-path PII redaction.** Packs that request it get known PII fields (email, phone, name, address, etc.) masked both in what's written to the audit log and in the data returned to the agent.

**MCP observability.** Where a site runs an MCP server built on `mcp-adapter` (as WooCommerce's does), Agent Safety registers as that adapter's observability handler (`McpRequestAuditHandler`) and audits denied and blocked tool calls at the MCP layer too — using the adapter's public API, with no changes to mcp-adapter itself.

Governed namespaces are opt-in: on a bare site the plugin governs nothing — namespaces are contributed by integration modules (the WooCommerce one governs `woocommerce/*`) or by the `agent_safety_governed_namespaces` filter. Governing a namespace without also mapping its verbs to tiers gets every call in it denied as an unknown verb — that's the fail-closed default working as intended, not a bug.

## Installation

Requirements: WordPress 6.9+, PHP 8.1+.

This is not yet packaged for the plugin directory. To run it from source:

```sh
git clone https://github.com/stephen1204paul/agent-safety.git
cd agent-safety
composer install
cd plugin
composer install
```

Then symlink or copy `plugin/` into `wp-content/plugins/agent-safety` and activate **Agent Safety** from the Plugins screen. WooCommerce support activates automatically if WooCommerce is active; nothing else to configure for it to be safe-by-default.

Uninstalling leaves the audit log and approval tables in place by default — it's a compliance record, and silently dropping it on uninstall would defeat the point. To have uninstall remove them, define `AGSAFE_REMOVE_DATA` as `true` before deleting the plugin.

## Configuration

Agent Safety is configured mostly through wp-admin (**Tools → Agent Capability Packs**, **Tools → Pending Agent Actions**, **Tools → Agent Audit Log**) plus a small set of filters for extending the policy programmatically:

| Filter | Purpose |
| --- | --- |
| `agent_safety_governed_namespaces` | Widen the set of ability namespaces the plugin governs beyond `core/*`, without writing a full integration module. |
| `agent_safety_verb_map` | Map additional verbs to tiers (`Tier` instance or its backing int: `0` Reversible, `1` SideEffecting, `2` Irreversible). Required companion to the namespace filter above — an unmapped verb in a governed namespace is denied, not allowed. |
| `agent_safety_pack_registry` | Replace or extend the `PackRegistry` (catalog + bindings) surfaced to the admin Packs UI, for bespoke pack catalogs. |
| `agent_safety_map_verb` | Extend tool-name → verb mapping for extensions registering their own namespaced abilities (used internally by the WooCommerce integration for `woocommerce-{resource}-{action}` → `woocommerce/{resource}-{action}`). |
| `agent_safety_elevation_rules` | Contribute argument-aware tier-elevation rules (`ElevationRule` instances), for a verb whose blast radius depends on its arguments — publish vs draft, bulk vs single. Companion to the two filters above. May only add rules, and a rule can only ever raise a tier, so this narrows and never widens. |
| `agent_safety_approval_summary` | Rewrite the human-facing summary of a pending action before it is stored (receives the flat summary, the verb, the input). A rewritten summary is rendered on the review screen through `wp_kses` allowing only `<a href>`; the binding (verb + args hash + principal) is outside the filter's reach. |
| `agent_safety_enable_grants` | **Default `false`.** Turn on pre-approval grants: a human authorises up to N future calls of one verb inside one scope, instead of clicking once per action. Nothing about grants happens while this is off. |
| `agent_safety_grant_eligible` | **Default `false`.** Decide whether an active grant covers THESE call arguments (receives the `Grant`, the verb and the args). Grants are per-verb, so this is where a host that knows which objects the human accepted says yes. A missing hook means no grant applies — never that every grant applies to any object. |
| `agent_safety_grant_ttl` | Hard grant lifetime in seconds, applied when a grant is issued (default 24 hours). |

Identity bindings (which pack applies to which application password, user, role, or WooCommerce API key) are managed under **Tools → Agent Capability Packs**; there is no code-level API for bindings beyond that screen and the registry filter above.

## Development

Run everything from the repo root:

```sh
composer install                              # core deps
cd plugin && composer install && cd ..        # plugin deps (path repo copies the core in — rerun after any core change)

composer check                                # lint + phpstan + both PHPUnit suites
```

Individually:

```sh
composer lint                                 # phpcs, PSR-12
composer phpstan                              # phpstan analyse, level 8, with wordpress-stubs
vendor/bin/phpunit                            # core suite — 60 tests, framework-agnostic
vendor/bin/phpunit -c plugin/phpunit.xml.dist # plugin suite — 102 tests, WP shims + adapter stub
vendor/bin/phpunit --filter GateTest          # a single test class
```

The core library (root `src/`) makes zero WordPress function calls — it's tested and type-checked as plain PHP. All WordPress and WooCommerce coupling lives in `plugin/`, which consumes the core as a Composer path dependency. Because that path repository copies rather than symlinks the core into `plugin/vendor`, `composer install` inside `plugin/` needs to be rerun after any change to root `src/`.

## Project layout

```
src/                         specflux/agent-safety-core — framework-agnostic, PHP >=8.1, no WP calls
  Policy/                    Tier, VerbCatalog, TierClassifier, ElevationRule
  Gate/                      Gate, GateContext, Decision, Outcome
  Packs/                     Pack, PackRegistry, LimitPolicy, LimitCheck
  Approval/                  ApprovalStore, ApprovalBinding
  Audit/                     AuditRecord, AuditDecision, AuditSink, HashChain, Redactor
tests/                       PHPUnit tests for the core

plugin/                      specflux/agent-safety — WordPress plugin host
  agent-safety.php           plugin bootstrap (main file)
  src/
    Identity/                IdentityChain + providers (app password, user/role)
    Hooks/                   PreToolCallGate, AbilityPermissionGate, AbilityAuditLog,
                             McpRequestAuditHandler, ToolCallResultRedactor
    Admin/                   wp-admin screens: Audit Log, Capability Packs, Pending Actions
    Audit/                   WpdbAuditSink, WpdbApprovalStore, AuditReader
    Support/                 PackResolver, RateLimitGate, ApprovalSweep, Schema, DecisionRecorder
    Integrations/Woo/        WooIntegration + everything WooCommerce-specific (verb mapping,
                             elevation rules, WC API key identity, packs) — loaded only when
                             WooCommerce is active
  tests/                     PHPUnit tests for the plugin (WP shims + mcp-adapter stub)
```

## Roadmap

Planned work — argument-aware spend caps, approval notifications, shadow mode,
a core-WordPress integration module, and more — is laid out in
[ROADMAP.md](ROADMAP.md).

## License

GPL-2.0-or-later.
