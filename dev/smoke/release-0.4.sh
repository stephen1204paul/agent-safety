#!/usr/bin/env bash
# End-to-end proof harness for the built 0.4.0 plugin ZIP, proving spec rows
# P1-P8. See dev/smoke/release-0.4/README.md for the full picture.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DIR/../.." && pwd)"
# research/mcp-adapter lives OUTSIDE this git worktree/repo, as a sibling
# checkout in the parent planning directory (this worktree's own root is the
# agent-safety repo, which does not contain research/). See README.md's
# deviations note.
RESEARCH_MCP_ADAPTER="$(cd "$REPO_ROOT/../research/mcp-adapter" && pwd)"

WORK=/tmp/agsafe-release-0.4-proof

# A previous run's `npx @wordpress/env stop` (deliberately never `destroy`,
# so an interactive re-run can poke at it) leaves the SAME database/files
# behind — including P1b's one-way `wp core multisite-convert` and any
# half-deleted plugin directory. Reusing that state silently corrupts every
# row (P1a's install fails on a leftover directory; P2's activation gets
# refused as multisite because the whole site already IS one). Destroy any
# leftover instance from a PRIOR proof run before starting, so every run of
# this harness begins from a guaranteed-fresh site — this is the harness
# destroying its OWN throwaway instance at the START of a fresh run, not the
# "never destroy" rule about leaving the environment for a human to poke at
# once the run finishes (see the trap below, still `stop` only). The destroy
# runs after the config is written (below), not on the config's presence:
# wp-env keys its Docker volumes by the config path, so the volumes outlive a
# wiped /tmp and a missing config file proves nothing.
mkdir -p "$WORK"

# shellcheck source=./release-0.4/lib.sh
source "$DIR/release-0.4/lib.sh"

log() { echo "[release-0.4] $*"; }
fail() {
    echo "[release-0.4] ERROR: $*" >&2
    exit 1
}

STOPPED=0
cleanup() {
    if [ "${AGSAFE_KEEP_ENV:-}" = "1" ]; then
        log "AGSAFE_KEEP_ENV=1 set — leaving wp-env running."
        return
    fi
    if [ "$STOPPED" -eq 1 ]; then
        return
    fi
    STOPPED=1
    log "stopping wp-env (not destroying)"
    (cd "$WORK" && npx @wordpress/env stop) || true
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# 1-3. Disk check.
# ---------------------------------------------------------------------------
check_disk_or_die

# ---------------------------------------------------------------------------
# 4. Build the 0.4.0 zip.
# ---------------------------------------------------------------------------
log "== building the 0.4.0 zip =="
(cd "$REPO_ROOT" && bin/build-zip.sh --restore-dev-deps)
VERSION="$(awk '/^[[:space:]]*\*[[:space:]]*Version:/ {print $NF; exit}' "$REPO_ROOT/plugin/agent-safety.php")"
[ -n "$VERSION" ] || fail "could not read Version: from plugin header"
ZIP_040="$REPO_ROOT/agent-safety-${VERSION}.zip"
[ -f "$ZIP_040" ] || fail "expected zip not found: $ZIP_040"
log "0.4.0 zip: $ZIP_040"

# ---------------------------------------------------------------------------
# 5. Build the 0.3 zip from release/senroflux-0.3 (upgrade leg).
# ---------------------------------------------------------------------------
log "== building the 0.3.0 zip (upgrade leg) =="
# $WORK survives across runs (only the docker instance gets destroyed at
# the top of the script), so these staging dirs must be wiped first or a
# second run's `mv` below fails against a leftover destination from the
# previous run.
rm -rf "$WORK/root-0.3"
mkdir -p "$WORK/root-0.3"
git -C "$REPO_ROOT" archive release/senroflux-0.3 | tar -x -C "$WORK/root-0.3"
(cd "$WORK/root-0.3/plugin" && composer install --no-dev --no-interaction)
mv "$WORK/root-0.3/plugin" "$WORK/root-0.3/agent-safety"
ZIP_030="$WORK/agent-safety-0.3.0.zip"
rm -f "$ZIP_030"
(cd "$WORK/root-0.3" && zip -rq -X "$ZIP_030" agent-safety -x 'agent-safety/tests/*' 'agent-safety/.git/*')
[ -f "$ZIP_030" ] || fail "expected 0.3 zip not found: $ZIP_030"
log "0.3.0 zip: $ZIP_030"

# ---------------------------------------------------------------------------
# 6. Export a read-only copy of mcp-adapter at pinned commit 07c9912.
#    git archive only READS from research/mcp-adapter (no working-directory
#    mutation) — never run any other git command against that checkout; it
#    deliberately carries uncommitted local work that must survive untouched.
# ---------------------------------------------------------------------------
log "== exporting mcp-adapter pinned at 07c9912 (read-only archive) =="
rm -rf "$WORK/mcp-adapter-pinned"
mkdir -p "$WORK/mcp-adapter-pinned"
git -C "$RESEARCH_MCP_ADAPTER" archive 07c9912 | tar -x -C "$WORK/mcp-adapter-pinned"
(cd "$WORK/mcp-adapter-pinned" && composer install --no-dev --no-interaction)

# ---------------------------------------------------------------------------
# 7. Generate the wp-env config.
# ---------------------------------------------------------------------------
log "== writing wp-env config =="
cat > "$WORK/.wp-env.json" <<EOF
{
    "\$schema": "https://schemas.wp.org/trunk/wp-env.json",
    "core": "https://wordpress.org/wordpress-7.1.2.zip",
    "phpVersion": "8.3",
    "port": 8970,
    "testsPort": 8971,
    "plugins": [
        "https://downloads.wordpress.org/plugin/woocommerce.11.1.0.zip",
        "$WORK/mcp-adapter-pinned"
    ],
    "mappings": {
        "wp-content/mu-plugins/agsafe-release-fixture.php": "$DIR/release-0.4/agsafe-release-fixture.php"
    },
    "config": {
        "WP_DEBUG": true,
        "WP_DEBUG_LOG": true,
        "WP_DEBUG_DISPLAY": false
    }
}
EOF
log "== destroying any leftover instance from a previous run =="
(cd "$WORK" && npx @wordpress/env destroy <<<'y') || true
# NOTE: if wordpress.org/wordpress-7.1.2.zip 404s (a WP release that has been
# pulled or renamed), fall back by editing "core" above to
# "WordPress/WordPress#7.1.2" (wp-env's GitHub-tag syntax). Not automated
# here because a silent version substitution would undermine the pin.
# NOTE: WP_ENVIRONMENT_TYPE is deliberately NOT set — P6 needs it unset so
# wp_get_environment_type() defaults to 'production'.

BASE_URL="http://localhost:8970"

# ---------------------------------------------------------------------------
# 8-9. Start wp-env, find the cli container.
# ---------------------------------------------------------------------------
start_wpenv_with_retry "$WORK"
CLI_NAME="$(find_cli_container)"
log "cli container: $CLI_NAME"

# wp-env's own default wp-config.php defines WP_ENVIRONMENT_TYPE as 'local'
# (verified empirically — `wp eval` printed 'local', not WordPress core's own
# unset-default of 'production'), even though nothing in our .wp-env.json
# config sets it. P6 needs it genuinely UNSET so wp_get_environment_type()
# falls through to WordPress core's own default. Delete the constant wp-env
# injected rather than trying to override it to 'production' by another
# route, so the row tests the real "nobody configured this" path.
log "== removing wp-env's own WP_ENVIRONMENT_TYPE default (P6 needs it unset) =="
cli_exec "$CLI_NAME" config delete WP_ENVIRONMENT_TYPE || true

# ---------------------------------------------------------------------------
# 10. Permalinks (required for /wp-json/ routes).
# ---------------------------------------------------------------------------
log "== activating WooCommerce + pretty permalinks =="
cli_exec "$CLI_NAME" plugin activate woocommerce
cli_exec "$CLI_NAME" option update permalink_structure '/%postname%/'
cli_exec "$CLI_NAME" rewrite flush --hard

ADMIN_LOGIN="$(cli_exec "$CLI_NAME" user list --field=user_login | head -1)"
log "default admin login: $ADMIN_LOGIN"
if [ "$ADMIN_LOGIN" != "admin" ]; then
    log "NOTE: wp-env's default admin login is '$ADMIN_LOGIN', not 'admin' — provision-woo.php assumes 'admin' when looking up the admin user; adjust if this ever differs."
fi
ADMIN_PASSWORD="password"

# ---------------------------------------------------------------------------
# 11. P1a — single-site install + activate.
# ---------------------------------------------------------------------------
log "== P1a: single-site install + activate =="
docker cp "$ZIP_040" "$CLI_NAME:/tmp/agent-safety-040.zip"
P1A_OK=1
cli_exec "$CLI_NAME" plugin install /tmp/agent-safety-040.zip --activate || P1A_OK=0
ACTIVE_LIST="$(cli_exec "$CLI_NAME" plugin list --status=active --field=name || true)"
if ! echo "$ACTIVE_LIST" | grep -q '^agent-safety$'; then
    P1A_OK=0
fi
if [ "$P1A_OK" -eq 1 ]; then
    echo "PASS  P1a: single-site install+activate"
else
    echo "FAIL  P1a: single-site install+activate"
fi

# ---------------------------------------------------------------------------
# 12. Tear down 0.4 install so P2 can start clean from 0.3.
# ---------------------------------------------------------------------------
cli_exec "$CLI_NAME" plugin deactivate agent-safety
cli_exec "$CLI_NAME" plugin delete agent-safety

# BUG FOUND AND FIXED (post-review): deleting the plugin's FILES drops
# nothing from the database — P1a's fleeting 0.4.0 activation just above
# already ran Schema::install() once, which creates the (empty) NEW
# `agsafe_grants` table via dbDelta. If that empty table survives into P2's
# 0.3 phase, `Schema::renameLegacyGrantsTable()` on the LATER 0.3->0.4.0
# upgrade sees BOTH the legacy `agent_safety_grants` (0.3's real data) and
# the new `agsafe_grants` (P1a's empty leftover) already present, treats it
# as "a crashed earlier rename" (its own documented, correct behavior for
# that ambiguous case — not a plugin defect) and refuses to migrate,
# stranding P2's seeded grant in the OLD table forever. In the real world
# nobody activates 0.4.0 before ever having run 0.3.x, so this ordering
# artifact is specific to this harness reusing one site for both P1 and P2;
# fix it here by dropping every table/option Agent Safety's fleeting P1a
# activation could have created, so P2 begins from a DB state indistinguishable
# from "0.4.0 was never on this site" — the real-world precondition P2 means
# to test.
cli_exec "$CLI_NAME" eval 'global $wpdb; foreach (["agsafe_audit_log", "agsafe_approvals", "agsafe_grants"] as $t) { $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}$t"); } $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE \"agsafe%\"");'

# ---------------------------------------------------------------------------
# 13. P2 setup (0.3 phase).
# ---------------------------------------------------------------------------
log "== P2 setup: installing 0.3.0 and seeding data =="
docker cp "$ZIP_030" "$CLI_NAME:/tmp/agent-safety-030.zip"
cli_exec "$CLI_NAME" plugin install /tmp/agent-safety-030.zip --activate

docker cp "$DIR/release-0.4/provision-woo.php" "$CLI_NAME:/tmp/agsafe-provision-woo.php"
docker cp "$DIR/release-0.4/query.php" "$CLI_NAME:/tmp/agsafe-query.php"
docker cp "$DIR/release-0.4/seed-shadow-and-grant.php" "$CLI_NAME:/tmp/agsafe-seed-shadow-and-grant.php"

PROVISION1_JSON="$(cli_exec "$CLI_NAME" eval-file /tmp/agsafe-provision-woo.php)"
echo "$PROVISION1_JSON"

ADMIN_USER_ID="$(cli_exec "$CLI_NAME" user get admin --field=ID 2>/dev/null || cli_exec "$CLI_NAME" user get "$ADMIN_LOGIN" --field=ID)"
log "admin user id: $ADMIN_USER_ID"

# Extract fields from the provisioning JSON (last line only, matching the
# reference setup.sh's node -e pattern).
export PROVISION1_JSON BASE_URL CLI_NAME ADMIN_USER_ID ADMIN_LOGIN ADMIN_PASSWORD WORK
P1_STATE="$(node -e '
  const fs = require("fs");
  const lines = process.env.PROVISION1_JSON.trim().split("\n").filter(Boolean);
  const state = JSON.parse(lines[lines.length - 1]);
  console.log(JSON.stringify(state));
')"
export P1_STATE
WC_KEY_ID="$(node -e 'console.log(JSON.parse(process.env.P1_STATE).key_id)')"
WC_CONSUMER_KEY="$(node -e 'console.log(JSON.parse(process.env.P1_STATE).consumer_key)')"
WC_CONSUMER_SECRET="$(node -e 'console.log(JSON.parse(process.env.P1_STATE).consumer_secret)')"
FIRST_PRODUCT_ID="$(node -e 'console.log(JSON.parse(process.env.P1_STATE).product_id)')"

log "== P2: filing a pending approval on the first product =="
# BUG FOUND AND FIXED (post-review): `node -e '<script>' VAR="$VAR"` puts
# VAR="$VAR" in process.argv, NOT process.env — only a `VAR=value` PREFIX
# before the command (or an actual `export`) sets the child's environment.
# The original form left WC_CONSUMER_KEY/WC_CONSUMER_SECRET/FIRST_PRODUCT_ID
# undefined inside the script (BASE_URL happened to work because it actually
# is `export`ed above), so this call silently sent
# `X-MCP-API-Key: undefined:undefined` with `id: null` — authenticating as
# nothing, filing no approval, and explaining P2's originally-empty pending
# approval id. Fixed by exporting these as real env vars via a `VAR=value`
# PREFIX, and by actually checking/logging the response instead of firing
# and forgetting it.
WC_CONSUMER_KEY="$WC_CONSUMER_KEY" WC_CONSUMER_SECRET="$WC_CONSUMER_SECRET" FIRST_PRODUCT_ID="$FIRST_PRODUCT_ID" BASE_URL="$BASE_URL" node -e '
  const BASE = process.env.BASE_URL;
  const MCP = BASE + "/wp-json/woocommerce/mcp";
  const ck = process.env.WC_CONSUMER_KEY, cs = process.env.WC_CONSUMER_SECRET;
  const pid = parseInt(process.env.FIRST_PRODUCT_ID, 10);
  (async () => {
    const headers = { "X-MCP-API-Key": `${ck}:${cs}`, "Content-Type": "application/json", Accept: "application/json, text/event-stream", Connection: "close" };
    const init = await fetch(MCP, { method: "POST", headers, body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-06-18", capabilities: {}, clientInfo: { name: "agsafe-p2-setup", version: "0.1.0" } } }) });
    const sid = init.headers.get("mcp-session-id");
    const h2 = Object.assign({}, headers, { "Mcp-Session-Id": sid, "MCP-Protocol-Version": "2025-06-18" });
    await fetch(MCP, { method: "POST", headers: h2, body: JSON.stringify({ jsonrpc: "2.0", method: "notifications/initialized" }) });
    const del = await fetch(MCP, { method: "POST", headers: h2, body: JSON.stringify({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name: "woocommerce-products-delete", arguments: { id: pid, force: true } } }) });
    console.log("P2 first delete response:", await del.text());
  })();
'

sleep 2
P2_PENDING_APPROVAL_ID="$(docker exec -e AGSAFE_SQL="SELECT approval_id FROM $(cli_exec "$CLI_NAME" config get table_prefix)agsafe_approvals WHERE verb='woocommerce/products-delete' AND status='pending' ORDER BY id DESC LIMIT 1" --user www-data "$CLI_NAME" wp eval-file /tmp/agsafe-query.php | tail -1 | node -e 'let d="";process.stdin.on("data",c=>d+=c);process.stdin.on("end",()=>{const r=JSON.parse(d);console.log(r[0]?r[0].approval_id:"");})')"
log "P2 pending approval id: $P2_PENDING_APPROVAL_ID"

log "== P2: filing a second approval and approving it (approved-unclaimed) =="
PROVISION2_JSON="$(docker exec -e AGSAFE_PRODUCT_NAME="agsafe-p2-second-$(date +%s)" --user www-data "$CLI_NAME" wp eval-file /tmp/agsafe-provision-woo.php)"
SECOND_PRODUCT_ID="$(echo "$PROVISION2_JSON" | tail -1 | node -e 'let d="";process.stdin.on("data",c=>d+=c);process.stdin.on("end",()=>{console.log(JSON.parse(d).product_id);})')"

# Same argv-vs-env fix as the first delete call above.
WC_CONSUMER_KEY="$WC_CONSUMER_KEY" WC_CONSUMER_SECRET="$WC_CONSUMER_SECRET" SECOND_PRODUCT_ID="$SECOND_PRODUCT_ID" BASE_URL="$BASE_URL" node -e '
  const BASE = process.env.BASE_URL;
  const MCP = BASE + "/wp-json/woocommerce/mcp";
  const ck = process.env.WC_CONSUMER_KEY, cs = process.env.WC_CONSUMER_SECRET;
  const pid = parseInt(process.env.SECOND_PRODUCT_ID, 10);
  (async () => {
    const headers = { "X-MCP-API-Key": `${ck}:${cs}`, "Content-Type": "application/json", Accept: "application/json, text/event-stream", Connection: "close" };
    const init = await fetch(MCP, { method: "POST", headers, body: JSON.stringify({ jsonrpc: "2.0", id: 1, method: "initialize", params: { protocolVersion: "2025-06-18", capabilities: {}, clientInfo: { name: "agsafe-p2-setup", version: "0.1.0" } } }) });
    const sid = init.headers.get("mcp-session-id");
    const h2 = Object.assign({}, headers, { "Mcp-Session-Id": sid, "MCP-Protocol-Version": "2025-06-18" });
    await fetch(MCP, { method: "POST", headers: h2, body: JSON.stringify({ jsonrpc: "2.0", method: "notifications/initialized" }) });
    const del = await fetch(MCP, { method: "POST", headers: h2, body: JSON.stringify({ jsonrpc: "2.0", id: 2, method: "tools/call", params: { name: "woocommerce-products-delete", arguments: { id: pid, force: true } } }) });
    console.log("P2 second delete response:", await del.text());
  })();
'

sleep 2
TABLE_PREFIX="$(cli_exec "$CLI_NAME" config get table_prefix)"
P2_SECOND_PENDING_ID="$(docker exec -e AGSAFE_SQL="SELECT approval_id FROM ${TABLE_PREFIX}agsafe_approvals WHERE verb='woocommerce/products-delete' AND status='pending' ORDER BY id DESC LIMIT 1" --user www-data "$CLI_NAME" wp eval-file /tmp/agsafe-query.php | tail -1 | node -e 'let d="";process.stdin.on("data",c=>d+=c);process.stdin.on("end",()=>{const r=JSON.parse(d);console.log(r[0]?r[0].approval_id:"");})')"

# BUG FOUND AND FIXED (post-review): `Api\Approvals::approve()`'s
# `authorized()` gate checks `current_user_can('manage_options')` for
# WHOEVER the request's CURRENT WordPress user is — never the $byUserId
# argument. A bare `wp eval-file` has no current user at all (confirmed:
# `wp eval 'var_dump(current_user_can("manage_options"));'` -> bool(false),
# `get_current_user_id()` -> 0), so this call was always refused and
# `approve()` always returned false, leaving the "approved-unclaimed"
# seed permanently `pending`. Fixed by setting the current user to the
# granting admin first.
APPROVE_SCRIPT="<?php defined('ABSPATH') || exit; wp_set_current_user((int) getenv('AGSAFE_APPROVE_BY')); \$ok = agent_safety()->approvals()->approve(getenv('AGSAFE_APPROVE_ID'), (int) getenv('AGSAFE_APPROVE_BY')); echo \$ok ? 'ok' : 'fail';"
echo "$APPROVE_SCRIPT" > "$WORK/approve-p2.php"
docker cp "$WORK/approve-p2.php" "$CLI_NAME:/tmp/agsafe-approve-p2.php"
docker exec -e AGSAFE_APPROVE_ID="$P2_SECOND_PENDING_ID" -e AGSAFE_APPROVE_BY="$ADMIN_USER_ID" --user www-data "$CLI_NAME" wp eval-file /tmp/agsafe-approve-p2.php
P2_APPROVED_APPROVAL_ID="$P2_SECOND_PENDING_ID"
log "P2 approved-unclaimed approval id: $P2_APPROVED_APPROVAL_ID"

log "== P2: seeding a grant + shadow window via seed-shadow-and-grant.php =="
# BUG FOUND AND FIXED (post-review, coordinator diagnosis confirmed against
# a live audit row: record_json carried "dry_run":true, "decision":"pending",
# "reason":"approval_required" for the P3 delete call): shadowing
# `woo-default-agent` here is the SAME pack every wc:<key_id> credential in
# this harness is bound to, so P3/P4/P5's own products-delete{force} calls
# were never bypassing the gate — they were being correctly evaluated as
# `approval_required`, then let through as an AUDITED DRY RUN because their
# own pack was shadowed (spec §3.3 item 11 / §3.4: shadow mode audits a
# blocking decision and lets the call proceed). P2 only needs a shadow
# window to exist as a Relaxation for the upgrade row's own assertions
# (survives the upgrade) — it never needs to be on a pack any test
# credential actually calls through. `readonly-analyst` (WooPacks.php) has
# no credential bound to it anywhere in this harness.
docker exec \
    -e AGSAFE_GRANT_VERB="woocommerce/products-delete" \
    -e AGSAFE_GRANT_SUBJECT="wc:${WC_KEY_ID}" \
    -e AGSAFE_GRANT_CORRELATION="agsafe-p2-e2e" \
    -e AGSAFE_GRANT_COUNT="3" \
    -e AGSAFE_GRANT_BY_USER_ID="$ADMIN_USER_ID" \
    -e AGSAFE_SHADOW_PACK="readonly-analyst" \
    -e AGSAFE_SHADOW_TTL_SECONDS="3600" \
    --user www-data "$CLI_NAME" wp eval-file /tmp/agsafe-seed-shadow-and-grant.php

# ---------------------------------------------------------------------------
# 14. P2 upgrade: delete files (DB rows survive), install 0.4.0.
# ---------------------------------------------------------------------------
log "== P2 upgrade: 0.3 -> 0.4.0 =="
cli_exec "$CLI_NAME" plugin deactivate agent-safety
cli_exec "$CLI_NAME" plugin delete agent-safety
docker cp "$ZIP_040" "$CLI_NAME:/tmp/agent-safety-040.zip"
cli_exec "$CLI_NAME" plugin install /tmp/agent-safety-040.zip --activate

# ---------------------------------------------------------------------------
# 15. Provision what P3-P7 need: admin app password + second WP user pack.
# ---------------------------------------------------------------------------
log "== provisioning admin app password + P5 second principal binding =="
PROVISION3_JSON="$(docker exec -e AGSAFE_MINT_APP_PASSWORD=1 -e AGSAFE_APP_PASSWORD_PACK="woo-default-agent" --user www-data "$CLI_NAME" wp eval-file /tmp/agsafe-provision-woo.php)"
echo "$PROVISION3_JSON"
export PROVISION3_JSON
P3_STATE="$(node -e '
  const fs = require("fs");
  const lines = process.env.PROVISION3_JSON.trim().split("\n").filter(Boolean);
  console.log(JSON.stringify(JSON.parse(lines[lines.length - 1])));
')"
export P3_STATE
ADMIN_APP_PASSWORD="$(node -e 'console.log(JSON.parse(process.env.P3_STATE).app_password_secret)')"
ADMIN_APP_PASSWORD_UUID="$(node -e 'console.log(JSON.parse(process.env.P3_STATE).app_password_uuid)')"

# ---------------------------------------------------------------------------
# Write state.json for run.js.
# ---------------------------------------------------------------------------
log "== writing state.json =="
cat > "$WORK/state.json" <<EOF
{
  "baseUrl": "$BASE_URL",
  "cliContainer": "$CLI_NAME",
  "wc_consumer_key": "$WC_CONSUMER_KEY",
  "wc_consumer_secret": "$WC_CONSUMER_SECRET",
  "wc_key_id": "$WC_KEY_ID",
  "admin_app_password": "$ADMIN_APP_PASSWORD",
  "admin_app_password_uuid": "$ADMIN_APP_PASSWORD_UUID",
  "admin_user_id": "$ADMIN_USER_ID",
  "admin_password": "$ADMIN_PASSWORD",
  "shop_manager_email": "agsafe_shop_manager@example.test",
  "p2_pending_approval_id": "$P2_PENDING_APPROVAL_ID",
  "p2_approved_approval_id": "$P2_APPROVED_APPROVAL_ID"
}
EOF
cat "$WORK/state.json"

# ---------------------------------------------------------------------------
# 16. Run the Node driver (P2's final assertions, P3, P4, P5, P6, P7, P8).
#     Do not let set -e kill the script here — later steps (P1b, the final
#     table) still need to run.
# ---------------------------------------------------------------------------
log "== running run.js (P2 assertions, P3-P8) =="
set +e
AGSAFE_STATE_PATH="$WORK/state.json" AGSAFE_RESULTS_PATH="$WORK/results.json" node "$DIR/release-0.4/run.js"
RUN_JS_EXIT=$?
set -e
log "run.js exit code: $RUN_JS_EXIT"

# ---------------------------------------------------------------------------
# 18. P1b — multisite, LAST (P8 inside run.js already uninstalled agent-safety).
# ---------------------------------------------------------------------------
log "== P1b: multisite network-activation refusal =="
cli_exec "$CLI_NAME" plugin install /tmp/agent-safety-040.zip
cli_exec "$CLI_NAME" core multisite-convert
# Network activation is EXPECTED to fail (that's the pass condition) — under
# `set -e` a bare `VAR="$(cmd)"` assignment still aborts the script if `cmd`
# exits non-zero (unlike `cmd || true`), so this one command must run with
# errexit off.
set +e
P1B_OUTPUT="$(cli_exec "$CLI_NAME" plugin activate agent-safety --network 2>&1)"
P1B_EXIT=$?
set -e
echo "$P1B_OUTPUT"
P1B_OK=0
if [ "$P1B_EXIT" -ne 0 ] || echo "$P1B_OUTPUT" | grep -q "Agent Safety does not support WordPress multisite. It was not activated."; then
    P1B_OK=1
fi
if [ "$P1B_OK" -eq 1 ]; then
    echo "PASS  P1b: multisite network-activation refused"
else
    echo "FAIL  P1b: multisite network-activation refused"
fi

if [ "$P1A_OK" -eq 1 ] && [ "$P1B_OK" -eq 1 ]; then
    P1_OK=1
else
    P1_OK=0
fi

# ---------------------------------------------------------------------------
# 19. Final consolidated table.
# ---------------------------------------------------------------------------
log "== final results =="
ROWS_JSON="$(cat "$WORK/results.json" 2>/dev/null || echo '{}')"
export ROWS_JSON P1_OK
OVERALL_EXIT=0
for ROW in P1 P2 P3 P4 P5 P6 P7 P8; do
    if [ "$ROW" = "P1" ]; then
        STATUS="$([ "$P1_OK" -eq 1 ] && echo PASS || echo FAIL)"
    else
        STATUS="$(ROW="$ROW" node -e '
          const rows = JSON.parse(process.env.ROWS_JSON || "{}");
          console.log(rows[process.env.ROW] === true ? "PASS" : "FAIL");
        ')"
    fi
    echo "$ROW  $STATUS"
    if [ "$STATUS" != "PASS" ]; then
        OVERALL_EXIT=1
    fi
done

exit "$OVERALL_EXIT"
