# Roadmap

What's planned for Agent Safety, roughly in order. No dates — items move up when
there's real demand for them (open an issue if one matters to you). Anything
here can change; the constraint that can't is the design principle: every
addition must fail closed, and nothing a tool or agent self-reports may ever
loosen a decision.

## Shipped for 0.2 (on main, unreleased)

**Argument-aware caps (spend limits).** Packs can declare caps read from the
call's arguments: a per-call value ceiling, a per-UTC-day spend total, a bulk
item-count cap, and a value threshold above which a call needs human approval
(routed through the existing approval flow). Fail-closed: a call that hides
or malforms the governed argument is denied; denials name the tripped cap in
the audit log like rate limits do, and never consume budget.

**Approval notifications.** Each genuinely new pending action (retries reuse
their row and stay silent) sends an email linking to the review screen, plus
an optional identifiers-only JSON webhook for Slack or anything else —
configured on **Tools → Pending Agent Actions**. The email deliberately has
no one-click approve link: a forwarded email must not be a grant.

**Shadow mode.** A per-pack "log only" toggle on the Capability Packs screen:
every decision is still evaluated and audited (marked `dry_run` in the
record), nothing is enforced, and no pending approvals are minted for calls
that already ran. Run a week of observation before turning enforcement on.

**Pack presets.** Three starter packs alongside the existing two:
`readonly-analyst` (reads only, write classes hard-denied), `fulfillment-bot`
(order updates, refunds unreachable by construction), and `refund-desk`
(every refund approval-gated and spend-bounded).

## Shipped for 0.3 (on main, unreleased)

**Core WordPress integration module.** The `core/` ability namespace is
governed unconditionally (D23): the three abilities WordPress ships today are
classified reversible, the six verbs proposed by the July 2026 core merge
proposal are pre-classified as named constants, and anything else in the
namespace fails closed as `unknown_verb`. Argument-aware elevation rules send
publishing/scheduling and bulk delete/trash of content, plus any user role or
capability change, to Tier 2; three starter packs ship (`site-readonly`,
`content-editor`, `site-admin-agent` — the last with a 25-item bulk cap).
Read-path redaction masks `user_pass`, `user_activation_key`, and `user_email`
on user-info results; logins stay visible so approvals can name their target.

**Programmatic approvals API.** A shared `Approvals` service (`agent_safety()->approvals()`)
exposes approve/reject/lookup gated by `manage_options` through the new
`agent_safety_can_approve` filter, writing the same hash-chained reconciliation
rows as the wp-admin form and firing `agent_safety_approval_resolved`. The
Pending Agent Actions screen delegates to it, so every approver takes one code
path.

**End-to-end tests in CI.** A de-hosted wp-env smoke drives mcp-adapter's HTTP
transport with an application password bound to `site-readonly`: an allowed
governed call lands in the audit chain, an unmapped verb in a governed
namespace is denied as `unknown_verb`, no unmasked user PII reaches the agent,
and the Audit Log page reports the chain intact. Runs on a WordPress
(latest/trunk) × Woo (off/on) matrix in GitHub Actions.

**Release automation.** Tagged releases build a WordPress-installable zip from
GitHub Actions, failing loudly when the tag does not match the plugin header.

## Later

- **WordPress.org plugin directory submission** — i18n/translation readiness
  and directory review compliance.
- **WP-CLI commands** — `wp agsafe audit list`, `wp agsafe audit verify`
  (hash-chain check), CSV export, approval management from the shell.
- **Audit retention and export** — configurable retention windows, webhook or
  syslog export for sites shipping logs to a SIEM.
- **Anomaly response** — velocity heuristics on the audit stream (an agent
  suddenly enumerating customers, a burst of denials) that can alert or
  suspend a token's bindings until a human looks.
- **Multisite** — per-site pack catalogs and bindings, network-level audit
  view.
- **MCP elicitation round-trip** — when mcp-adapter supports elicitation,
  an approval-required verdict could round-trip to the agent's client for an
  in-conversation human decision instead of parking in wp-admin.

## Upstream (WordPress/mcp-adapter)

The MCP observability handler works against the adapter's public API today,
with known limits that need upstream changes rather than plugin workarounds:

- **Stable `error_code` on the `CallToolResult`-error branch** of `mcp.request`
  observability events, so consumers can classify permission-denied vs
  execution failure without matching translated display strings.
- **`$request_id` on `mcp_adapter_pre_tool_call`**, so pre-call subscribers can
  correlate with the observability event for the same request.

## Non-goals

- **Agent-side safety.** This plugin governs the server side of tool calls. It
  does not filter prompts, inspect model output, or try to make the agent
  itself behave — it assumes the agent can be wrong and bounds the damage.
- **Replacing WordPress capabilities.** Packs layer on top of the existing
  permission model; a call still needs to pass the underlying capability
  checks. Agent Safety narrows what a credential may do, never widens it.
