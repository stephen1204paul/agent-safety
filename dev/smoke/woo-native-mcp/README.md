# Stage 3: Woo-native-MCP e2e

A live wp-env leg proving Agent Safety governs **WooCommerce 11.1's own native
MCP server** — Woo's (deprecated, off-by-default) `/wp-json/woocommerce/mcp`
transport, running on the mcp-adapter v0.3.0 Woo bundles itself, not the
standalone mcp-adapter the core-module smoke (`dev/smoke/smoke-core.js`)
drives. See spec §3.2 item 5, §1's "Woo's MCP transport" bullet, and §6's
hook-ordering risk.

It also answers ticket 01's open runtime question: does Agent Safety's
`wp_register_ability_args` filter register before WooCommerce registers its
own abilities on a live site, so Woo's abilities are actually wrapped? Yes —
see "Hook-ordering evidence" below.

## Prerequisites

- Docker running, with headroom on the Docker VM disk (the harness checks
  this itself and refuses to start above 90% used).
- Node 22+, `npm install` already run in this directory (`@wordpress/env`).
- `plugin/vendor` installed with `--no-dev` in the repo root (already true in
  the release worktree; if working from a fresh checkout, run
  `composer install --no-dev` in `plugin/` first).
- Nothing else running on ports 8950/8951.

## How to run

```sh
cd dev/smoke/woo-native-mcp
./setup.sh      # starts wp-env, activates plugins, enables Woo's
                # mcp_integration feature, provisions a Shop Manager +
                # bound REST key + product, writes state.json
node run.js     # runs the three cases + control against the live site
```

`setup.sh` is idempotent for re-runs against an already-started environment
(it re-provisions a fresh REST key and reuses the existing product/user).

When done:

```sh
npx @wordpress/env stop   # do NOT `destroy` — leaves the env for a re-run
```

## What each case proves

The environment: WordPress (latest), PHP 8.3, WooCommerce **11.1.0** pinned
(the exact `downloads.wordpress.org` zip, not `trunk` or `latest`), Agent
Safety mounted from `../../../plugin`, **no standalone mcp-adapter** — Woo
11.1 bundles its own (v0.3.0) — `WP_DEBUG`/`WP_DEBUG_LOG` on, and an mu-plugin
fixture (`agsafe-woo-mcp-fixture.php`) that:

1. Lets Woo's MCP transport accept plain HTTP (wp-env has no TLS) via the
   `woocommerce_mcp_allow_insecure_transport` filter — dev-only, never ship.
2. Registers one test-only ability, `woocommerce/agsafe-smoke-widget-list`,
   with `expose_in_deprecated_woocommerce_mcp => true` metadata (the same
   metadata Woo's own REST-derived abilities carry) so it is genuinely
   reachable through Woo's MCP tool list — not a namespace Agent Safety
   invented, a real uncatalogued ability on Woo's own endpoint.

`setup.sh` provisions a **Shop Manager** user (not an administrator — a pass
here can't come from admin capabilities), a WooCommerce REST API key with
`read_write` permission for that user (built the same way
`WC_Ajax::update_api_key()` does, via `wp eval-file`), binds `wc:<key_id>` to
`woo-default-agent` in `agsafe_pack_bindings`, and seeds one product.

`run.js` calls `/wp-json/woocommerce/mcp` with `X-MCP-API-Key: <ck>:<cs>`:

- **Case 1** — `woocommerce/products-list` (tool `woocommerce-products-list`)
  is allowed and returns the seeded product. Proves the gate's allow path
  works transparently through Woo's own transport.
- **Case 2** — `woocommerce/products-delete {id, force: true}` (elevated to
  tier 2 by `ForceDeleteElevationRule`) returns `approval_required` with an
  `approval_id`, and the product still exists afterwards. Proves the
  gate blocks and parks an irreversible call rather than letting Woo's
  bundled, older mcp-adapter execute it — this is the seam
  `AbilityPermissionGate` uses specifically because it does NOT depend on
  `mcp_adapter_pre_tool_call` (absent from mcp-adapter 0.3.0): it wraps the
  ability's own `permission_callback` via the WordPress-core
  `wp_register_ability_args` filter, so it works on any mcp-adapter version.
- **Case 3** — the fixture's uncatalogued `woocommerce/` ability is refused
  with `unknown_verb`. Proves the fail-closed default: a real ability on
  Woo's own endpoint that `WooVerbCatalog::MAP` doesn't name is denied, not
  silently ungoverned.
- **Control** — the audit rows for cases 1 and 2 name principal
  `wc:<key_id>` and pack `woo-default-agent`, never the fallback
  `default-agent` (`PackRegistry::DEFAULT_PACK`). This is the only way to
  know the verdict came from the *bound* pack and not a default that happens
  to allow the same calls.
- Also checked on every run: `debug.log` carries no PHP warning, notice, or
  deprecation notice attributable to Agent Safety.

## Hook-ordering evidence (ticket 01)

`AbilityPermissionGate::register()` calls
`add_filter('wp_register_ability_args', ...)` from the plugin's
`plugins_loaded` (priority 0) callback in `plugin/agent-safety.php`. Adding a
filter callback only requires that the `add_filter()` call itself run before
WordPress fires `apply_filters('wp_register_ability_args', ...)` for a given
ability — filter *priority* only orders multiple callbacks on the *same*
hook, it says nothing about hooks that fire at different times. WooCommerce
registers abilities no earlier than `init`/`wp_abilities_api_init`, which
fires strictly after `plugins_loaded`. So statically, Agent Safety's filter
is guaranteed to already be registered by the time Woo's abilities register.

This harness proves it empirically, not just by that reading: case 2 filing
a pending approval, and case 3 being refused as `unknown_verb`, are both
observable *only* if AS's wrapped `permission_callback` actually ran for a
Woo-registered ability the plugin never registered itself. The regression
check below (temporarily un-cataloguing `products-list`) additionally shows
the SAME live site flip from allowed to denied purely by editing
`WooVerbCatalog::MAP` and restarting — proof the wrap is live on Woo's own
abilities, not a static reading of the source.

**Answer: yes, on a live WooCommerce 11.1.0 + WP site, Agent Safety's
`wp_register_ability_args` filter registers before WooCommerce registers its
abilities, and Woo's abilities are wrapped.**

## Regression check (spec §4 row 3 failable check)

```sh
# 1. Green baseline
node run.js

# 2. Break it: delete the woocommerce/products-list entry from
#    WooVerbCatalog::MAP. plugin/ is a DIRECTORY bind mount here (not a
#    single-file mount), so wp-env picks the edit up live — no restart
#    needed (verified: RED appeared without touching wp-env).
#    ... edit plugin/src/Integrations/Woo/WooVerbCatalog.php ...
node run.js                  # case 1 goes RED: unknown_verb, not allowed

# 3. Restore WooVerbCatalog::MAP (git diff empty), rerun — GREEN again.
```

Captured output (2026-09-24), case 1 only:

```
# RED (products-list removed from WooVerbCatalog::MAP)
FAIL  woocommerce-products-list executes without error   [... "Permission denied: Blocked by Agent Safety (woo-default-agent): unknown_verb" ...]
FAIL  products-list response mentions the seeded product
FAIL  audit row(s) allowed for woocommerce/products-list   [before=3 after=3]

# GREEN (restored, `git diff` on the file empty)
PASS  woocommerce-products-list executes without error
PASS  products-list response mentions the seeded product
PASS  audit row(s) allowed for woocommerce/products-list
```

## Live findings — one fixed defect, one documented transport limitation

Both were **real, reproduced** on this harness, not harness bugs; both failed
the same way on every run, restart or not. This harness deliberately keeps
testing the SPEC'S stated expectation (not a weakened version of it), which
is why finding 1's regression stayed visible until it was fixed, and why
finding 2 still asserts on the spec's stated shape rather than a version
weakened to dodge it.

### 1. FIXED (`3791b4d`): the audit principal was `user:<id>`, not `wc:<key_id>`, for Woo MCP calls

`WooCommerceRestTransport::authenticate()` calls `wp_set_current_user($user->ID)`
as a side effect of validating the API key (`WooCommerceRestTransport.php`,
WooCommerce 11.1.0). `RequestContext::tokenId()` — the audit actor field —
used to return the FIRST candidate token across the WHOLE identity chain,
and the chain order is `[ApplicationPasswordIdentity, UserRoleIdentity,
WcApiKeyIdentity]` (`plugin/agent-safety.php`, `WooIntegration::register()`
appends the Woo provider LAST). So for a Woo-authenticated request,
`UserRoleIdentity`'s `user:<id>` token was always first, and
`WcApiKeyIdentity`'s `wc:<key_id>` token was always last.

`PackResolver::resolve()` was unaffected — it walks the whole list looking
for the first BOUND token, finds `wc:<key_id>`, and resolves
`woo-default-agent` correctly (verified: `row1.pack === 'woo-default-agent'`,
never the fallback `default-agent`). Only the AUDIT actor field was wrong:

```json
"actor":{"token_id":"user:2","wp_user":2}
```

for a call authenticated purely by the WooCommerce REST key `wc:1` (no
application password, no logged-in session). The pack decision was
demonstrably right; the audit trail's *stated reason* (which principal
earned it) was demonstrably wrong. This was exactly the identity-timing class
of bug this repo's own `CLAUDE.md` calls out as one unit tests keep missing.

**Fix (commit `3791b4d`):** the request's principal is now whichever
identity-chain token actually WON the pack binding (falling back to the
first token, then null), computed by `PackResolver::principal()` and wired
into `RequestContext` through an injected resolver
(`RequestContext::configurePrincipalResolver()`) set at plugin bootstrap
alongside `$agsafe_packs`. Pack resolution itself is unchanged. Covered by
unit tests (`PackResolverTest`, a `VerdictPipelineTest` case asserting both
the approval `key_id` and the audit actor `token_id`); this harness's Case-2
Control assertions (`run.js`) now pass on the same real Woo MCP request that
originally surfaced the bug.

### 2. Documented transport limitation: Woo's bundled mcp-adapter v0.3.0 drops the WP_Error's structured data

The tool-call response for the approval-required case is:

```json
{"jsonrpc":"2.0","id":4,"result":{"content":[{"type":"text","text":"Permission denied: \"woocommerce/products-delete\" is irreversible and requires human approval before it can run. A request has been logged for review."}],"isError":true}}
```

`Verdict::error()`'s tier-neutral message survives, prefixed with
"Permission denied: ", but the WP_Error's `data` array (`status`, `verb`,
`tier`, `approval_id`) never reaches the client — this mcp-adapter version's
`ToolsHandler` only serializes `get_error_message()` into the CallToolResult,
not `get_error_data()`. The approval genuinely exists — confirmed directly
against `wp_agsafe_approvals` (`status='pending'`, `verb='woocommerce/products-delete'`,
a real `apr_...` id) and the audit row (`tier: 2`, `reason: "approval_required"`,
`approval: {"id": "apr_..."}`) — it just isn't visible to an agent reading
only the MCP response, on this transport. This reinforces, with a concrete
example, the gap spec §3.2 item 7 already documents ("agents connected only
through Woo's MCP server can't see `agent-safety/check-approval`" either): an
agent working purely against Woo's own endpoint cannot self-serve an
approval id from the tool-call response and would need the admin's Pending
Actions page.

**Orchestrator decision: this is a transport limitation, not an Agent Safety
defect**, and is not fixed here — it lives in Woo's bundled mcp-adapter
version, outside this plugin. `run.js`'s Case 2 asserts what actually crosses
this transport (`isError: true`, an approval message for the verb, the
product still existing) and treats "the approval was actually filed" —
including its `key_id` — as DB ground truth against `wp_agsafe_approvals`
and the audit log, checked independently of the MCP response body. The
message-text assertion matches spec §3.11's base `approval_required` text
exactly.
