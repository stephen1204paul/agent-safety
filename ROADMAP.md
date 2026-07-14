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

## Next (0.3)

**Core WordPress integration module.** Govern `core/*` content abilities
(posts, media, users) the way the WooCommerce module governs `woocommerce/*`:
a verb map, tier classifications, and default packs for content-editing
agents. This is what makes "WordPress-general, WooCommerce as one capability
pack" concrete.

**End-to-end tests in CI.** The unit suites are thorough but the bugs that
matter most have been the ones only a real WordPress request path exposes
(identity timing, DB-backed transient types, double permission checks). Add a
wp-env-based smoke suite to CI that exercises the live gate, approval, and
audit flow over HTTP.

**Release automation.** Tagged releases build an installable plugin zip
(plugin directory plus its vendored core) from GitHub Actions.

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
