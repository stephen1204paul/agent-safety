=== Agent Safety ===
Contributors: specflux
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A governed safety and audit layer for AI agent tool calls: verb-tier gating, capability packs, human approval, and a compliance-grade audit trail.

== Description ==

Agent Safety sits between AI agents (via the WordPress Abilities API, and the
[mcp-adapter](https://github.com/WordPress/mcp-adapter)-based MCP integration WooCommerce and
other plugins use to expose one) and the actions those agents can take on your site. It is a
WordPress-general governance engine: on its own it classifies and audits `core/*` abilities, and
each supported plugin adds its verb knowledge through a capability-pack **integration module**.
WooCommerce ships as the flagship integration, not a requirement — the plugin activates and
runs on any WordPress 6.9+ site, WooCommerce or not.

**What it does:**

* **Verb-tier gating.** Every governed ability is classified into a tier — read/reversible,
  side-effecting, or irreversible — using the plugin's own policy map, not a plugin's
  self-reported annotations. An ability that reports itself read-only but is classified as a
  write is refused rather than trusted.
* **Capability Packs.** A pack is a named, scoped view of the verb catalog (an allow-list, a
  hard-denied tier class, an approval requirement per tier, a PII-redaction policy, and optional
  rate limits) bound to an identity — a WordPress application password, a specific user or role,
  or, when WooCommerce is active, a WooCommerce REST API key. An unbound credential falls back to
  a safe default pack.
* **Human approval for irreversible actions.** An agent call classified as irreversible (and not
  hard-denied by its pack) is blocked and queued under **Tools → Pending Agent Actions**, where a
  human can approve (minting a single-use, time-limited token the agent must present to proceed)
  or reject it.
* **Hash-chained audit log.** Every gate decision and executed action is written to an
  append-only, hash-chained log, viewable and CSV-exportable under **Tools → Agent Audit Log**,
  with a tamper-evidence check that re-verifies the chain on load.
* **Rate limits.** A pack can cap calls per minute and per hour per identity; a denied call never
  consumes quota.
* **Spend limits.** A pack can also cap what a call's *arguments* say: a per-call value ceiling,
  a per-day spend total, a bulk item-count cap, or a value threshold above which the call needs
  human approval. A call that hides the governed value is denied, and denials never consume
  budget.
* **Approval notifications.** Each new pending action emails a link to the review screen, and an
  optional webhook (identifiers only — no call arguments leave the site) can route to Slack or
  anything else.
* **Shadow mode.** Toggle any pack to "log only": every decision is still audited (marked
  dry-run) but nothing is enforced — observe a week of would-be denials before turning
  enforcement on.
* **Starter packs.** Bind a credential to a preset instead of authoring policy: a read-only
  analyst, a fulfillment bot that can never refund, a refund desk that is approval-gated and
  spend-bounded.
* **PII redaction.** Known PII fields (email, phone, name, address, etc.) are masked both in what
  is written to the audit log and, for packs that request it, in the data returned to the agent.
* **MCP (mcp-adapter) integration.** Where a site runs an MCP server built on the WordPress
  `mcp-adapter` project (as WooCommerce's does), Agent Safety also audits denied and blocked tool
  calls at the MCP layer, using the adapter's public observability API — no adapter changes
  required.

WooCommerce support (verb classification for products/orders, order-fulfillment and bulk-delete
elevation rules, and WooCommerce REST API key identity) lives entirely in a self-contained
integration module and is wired up automatically only when WooCommerce is active.

= Data policy =

By default, uninstalling the plugin keeps the audit log and approval data intact — it is a
compliance record, and removing it silently on uninstall would defeat its purpose. A site
operator who wants the tables and settings dropped can opt in explicitly (see the FAQ).

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. The plugin is WordPress-general and governs the core Abilities API on any site. WooCommerce
is supported as a capability-pack integration module that is only loaded when WooCommerce is
active; without it, the plugin still installs, gates `core/*` abilities you choose to govern via
the `agent_safety_governed_namespaces` filter, and provides the audit log and approval queue.

= What happens to my data on uninstall? =

Nothing, by default. Deleting the plugin from the Plugins screen leaves the audit log and
approvals tables and plugin options in place. To have uninstall remove them, define
`AGSAFE_REMOVE_DATA` as `true` (for example in `wp-config.php`) before deleting the plugin.

= Does it work with the WordPress MCP adapter? =

Yes. Where a site's MCP server is built on the `mcp-adapter` project (as WooCommerce's MCP
integration is), Agent Safety registers as that adapter's observability handler and records an
audit entry for every tool call outcome, including calls denied or blocked before execution — in
addition to gating and auditing ability calls directly through the WordPress Abilities API.

= How are AI agents identified? =

By whichever credential authenticated the request: a WordPress application password, a logged-in
user or their role, or — when WooCommerce is active — the WooCommerce REST API key used to
authenticate the request. Each is bound to a capability pack independently under **Tools → Agent
Capability Packs**; an unbound credential gets a safe default pack rather than full access.

== Changelog ==

= 0.1.0 =
* Initial release: verb-tier gating over the WordPress Abilities API, capability packs with
  per-identity binding and rate limits, human approval queue for irreversible actions,
  hash-chained audit log with wp-admin viewer and CSV export, read-path PII redaction, MCP
  (mcp-adapter) observability-based audit consumer, and a WooCommerce capability-pack
  integration module.
