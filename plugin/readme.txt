=== Agent Safety ===
Contributors: stephen1204paul
Tags: security, ai, mcp, audit-log, woocommerce
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Governs other plugins' agent tool calls, with human approval and a tamper-evident audit log.

== Description ==

Agent Safety governs other plugins' agent tool calls, not just its own. It sits between AI
agents (through the WordPress Abilities API, and through MCP servers built on
[mcp-adapter](https://github.com/WordPress/mcp-adapter), as WooCommerce's is) and the actions
those agents can take on your site. Every governed call is classified by tier, checked against a
capability pack bound to the calling identity, and written to a tamper-evident, hash-chained
audit log, before it runs.

The plugin is WordPress-general. It ships a `core/*` module that runs on every site, and a
WooCommerce module that wires in automatically when WooCommerce is active. Neither one trusts a
tool's own "read-only" or "safe" label; classification comes from the plugin's own policy map.

**Capability packs and tiers**

Every governed ability maps to a tier: reversible (read), side-effecting (write), or
irreversible (delete, refund, cancel). A capability pack is a named, scoped view of the verb
catalog for one identity — a WordPress application password, a user or role, or a WooCommerce
REST API key — with an allow list, a hard-denied tier class, a per-tier approval requirement, and
optional rate and spend limits. A credential with no explicit binding falls back to a safe
default pack rather than inheriting full access.

**Human approval**

When a pack requires approval for a tier (the starter packs do for irreversible calls), a call
at that tier doesn't execute. It is queued under **Tools → Pending Agent Actions** for a human
to approve or reject. When an
Approval is requested, Agent Safety captures a fingerprint of the target's current state; when
the approval is claimed, it re-checks that fingerprint. A mismatch means the target changed
between request and claim, so the old approval is marked stale and a fresh request is filed. This
narrows the gap between a human's approval and the write — it does not close it, because the
short window between claiming an approval and executing the call still exists.

**Self-service approval status**

An agent can call `agent-safety/check-approval` with an `approval_id` to poll its own request
without a human in the loop. It reports a reduced, public status (pending, approved, rejected,
expired, used, or superseded) and a plain-English `next_action`, and never returns the approver's
identity, the original arguments, or any token.

**Hash-chained audit log**

Every gate decision and executed action is appended to an append-only, hash-chained log under
**Tools → Agent Audit Log**, with a tamper-evidence check that re-verifies the chain on load.

**Core coverage**

Governs the three abilities WordPress core registers today. Any other `core/*` ability is denied
until Agent Safety maps it, so future core write abilities fail closed rather than run
ungoverned.

**WooCommerce coverage**

When WooCommerce is active, Agent Safety governs exactly these 16 abilities:

* `woocommerce/products-list`
* `woocommerce/products-get`
* `woocommerce/products-create`
* `woocommerce/products-update`
* `woocommerce/products-delete`
* `woocommerce/products-query`
* `woocommerce/product-create`
* `woocommerce/product-update`
* `woocommerce/product-delete`
* `woocommerce/orders-list`
* `woocommerce/orders-get`
* `woocommerce/orders-create`
* `woocommerce/orders-update`
* `woocommerce/orders-query`
* `woocommerce/order-add-note`
* `woocommerce/order-update-status`

Everything else under `woocommerce/` is refused as an unknown verb. Deleting a product with
`force: true` is classed irreversible; a plain delete (trash) is side-effecting.

**Which MCP endpoint to use**

WooCommerce's own MCP endpoint (`/wp-json/woocommerce/mcp`) is deprecated by WooCommerce itself,
which points clients at the shared `mcp-adapter` server instead. Agent Safety governs both the
same way, through the ability permission check, but the two endpoints don't expose the same
information to an agent:

* On the shared `mcp-adapter` server, an agent that hits `approval_required` gets the full error
  data, including `approval_id`, and can call `agent-safety/check-approval` to poll it.
* On WooCommerce's own endpoint, the bundled `mcp-adapter` 0.3.0 only forwards the error message
  text to the client, not the structured error data. An agent sees the message "needs human
  approval" but not the `approval_id`, and `agent-safety/check-approval` isn't reachable on that
  transport at all — the approval still exists and is visible on the Pending Actions page, but
  the agent can't self-serve it.

We recommend running agents against the shared mcp-adapter server. WooCommerce's own endpoint is
supported for as long as WooCommerce ships it, with the limitation above.

**Environment awareness**

Agent Safety notices when a site's address changes (a migration, a staging clone) and treats it
as a new environment: shadow-mode windows, active grants, and approved-but-unclaimed approvals
are all voided rather than carried over silently, and an admin notice stays up until someone
rebinds the site from the settings page. Production sites (`wp_get_environment_type() ===
'production'`, WordPress's own default) never default to shadow mode and cap any shadow window at
24 hours.

**Multisite**

WordPress multisite is not supported. Activation is refused on a multisite install, network-wide
or per site.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/agent-safety`, or install it through the Plugins
   screen.
2. Activate **Agent Safety**.
3. Visit **Tools → Agent Capability Packs** to bind identities (application passwords, users or
   roles, WooCommerce REST API keys) to a pack. Unbound credentials get a safe default pack.
4. Review pending actions under **Tools → Pending Agent Actions** and the log under **Tools →
   Agent Audit Log**.

WooCommerce support activates automatically when WooCommerce is active; there is nothing else to
configure for it to be safe by default.

== Frequently Asked Questions ==

= Does this require WooCommerce? =

No. The plugin is WordPress-general and governs the core Abilities API on any site. WooCommerce
support is a self-contained module that loads only when WooCommerce is active.

= Does it work on multisite? =

No. Activation is refused on a multisite install, both network-wide and per site.

= What about WooCommerce's own MCP endpoint? =

It's governed the same way as the shared mcp-adapter server, but WooCommerce's bundled
mcp-adapter version doesn't forward the structured `approval_required` error data to the client,
so an agent on that endpoint can see the approval message but not the `approval_id`, and can't
reach `agent-safety/check-approval`. See the Description for details. We recommend the shared
mcp-adapter server; WooCommerce's own endpoint is supported while WooCommerce continues to ship
it.

= What happens to my data on uninstall? =

Nothing, by default. Deleting the plugin from the Plugins screen leaves the audit log, approval
tables, and plugin options in place, because the audit log is a tamper-evident security record.
To have uninstall remove everything (all three tables, every option, every scheduled cron event),
define `AGSAFE_REMOVE_DATA` as `true` (for example in `wp-config.php`) before deleting the
plugin.

= How are AI agents identified? =

By whichever credential authenticated the request: a WordPress application password, a logged-in
user or their role, or — when WooCommerce is active — the WooCommerce REST API key used to
authenticate the request. Each is bound to a capability pack independently under **Tools → Agent
Capability Packs**.

== External services ==

This plugin can send data outside your site in two ways.

**Email.** When a new agent action needs approval, Agent Safety sends an email through
`wp_mail()` to the site's admin email address, or to a recipient set on the Pending Agent
Actions screen, linking to the login-protected review screen. It goes through your site's own
mail delivery.

**Webhook (opt-in).** An administrator can set a webhook URL on the Pending Agent Actions
screen. When
set, every new pending approval sends an HTTP POST to that URL with a JSON body containing the
event name, the approval id, the verb (ability) that was called, and a link to the review screen.
No call arguments are sent. The webhook is off until an administrator enters a URL, and the
destination is the site operator's choice. Nothing is sent to the plugin's author.

== Privacy ==

Agent Safety keeps a hash-chained audit log of gate decisions and executed agent actions,
including the identity that made the call. A personal-data export for a WordPress user includes
their matching audit rows. An erasure request does not delete them: they are reported as
retained, with a note that they're kept as a tamper-evident security record, because rewriting a
row would break the hash chain that proves the log hasn't been tampered with. Matching is done by
resolving the requester's email to a WordPress user and matching rows on that user only; recorded
tool inputs are never searched for personal data, but they may contain it, since they can include
data handled by other plugins (customer records, order details) that the calling agent's tool
touched.

== Upgrade Notice ==

= 0.4.0 =
Schema change: the grants table is renamed and the approvals table gains new columns. Back up
your database before upgrading. There is no downgrade path back to a pre-0.4.0 build once this
runs.

== Changelog ==

= 0.4.0 =
* `core/*` module narrowed to the three abilities WordPress core actually registers
  (`get-site-info`, `get-user-info`, `get-environment-info`); any other `core/*` ability is now
  refused as an unknown verb instead of matching a speculative merge-proposal verb.
* WooCommerce module narrowed to the 16 abilities WooCommerce 11 ships; forward-compatibility
  entries for abilities WooCommerce hasn't shipped yet are removed, and anything else under
  `woocommerce/` is refused.
* `woocommerce/product-delete` is now side-effecting, and irreversible only with `force: true`,
  matching `products-delete`.
* Added a state fingerprint on Approvals: the target's revision marker is captured at request
  time and re-checked at claim time. A mismatch marks the old approval stale and files a fresh
  one. This narrows the window between a human's approval and the write; it does not close it.
* Added environment awareness: a site-address change voids shadow windows, grants, and
  unclaimed approvals rather than carrying them over silently, with an admin notice until the
  site is rebound.
* Added a self-service approval check: `agent-safety/check-approval` lets an agent poll the
  status of its own pending request.
* Directory-compliance pass: i18n on every user-facing string, an opt-in uninstall that now
  covers every option and cron event, disclosed audit-row retention through erasure requests
  documented in the exporter and privacy policy, and the grants table renamed to
  `agsafe_grants`.
* Bumped minimum requirements to WordPress 7.0 and PHP 8.1.
* No downgrade path: the grants table was renamed as part of this release's schema change, so a
  0.3.x build cannot read a 0.4.0 database.
