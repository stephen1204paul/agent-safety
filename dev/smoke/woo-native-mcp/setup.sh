#!/usr/bin/env bash
# Stage 3 wp-env leg: starts the Woo-native-MCP environment, activates
# plugins, enables Woo's mcp_integration feature, provisions a Shop Manager
# + bound REST key + product, and writes dev/smoke/woo-native-mcp/state.json
# for run.js to consume. See README.md for the full picture.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

echo "== disk check =="
DISK_LINE="$(docker run --rm alpine df -h / | tail -1)"
echo "$DISK_LINE"
USE_PCT="$(echo "$DISK_LINE" | awk '{print $5}' | tr -d '%')"
if [ "$USE_PCT" -gt 90 ]; then
    echo "Docker VM disk is ${USE_PCT}% full (>90%). Stopping — do not prune automatically." >&2
    exit 1
fi

echo "== starting wp-env (ports 8950/8951) =="
npx @wordpress/env start

CLI_NAME="$(docker ps --format '{{.Names}}' | grep -- '-cli-1$' | grep -v tests | head -1)"
if [ -z "$CLI_NAME" ]; then
    echo "Could not find the wp-env cli container." >&2
    exit 1
fi
# The container's default user, not www-data: on a Linux host the bind-mounted
# WordPress files belong to the host user, and www-data can't write the
# .htaccess the permalink flush below needs (every /wp-json/ route 404s).
CLI=(docker exec "$CLI_NAME" wp)

echo "== activating plugins =="
"${CLI[@]}" plugin activate woocommerce agent-safety

# wp-env's default permalink structure is plain (?p=123), under which WordPress
# never registers the /wp-json/ pretty rewrite rules a REST/MCP route needs —
# every route 404s. Woo's MCP endpoint is /wp-json/woocommerce/mcp, so this is
# required, not cosmetic.
echo "== enabling pretty permalinks (required for /wp-json/ routes) =="
"${CLI[@]}" option update permalink_structure '/%postname%/'
"${CLI[@]}" rewrite flush --hard

echo "== copying helper scripts into the cli container =="
docker cp query.php "$CLI_NAME:/tmp/agsafe-query.php"

echo "== provisioning (Shop Manager, bound REST key, product, feature flag) =="
docker cp provision.php "$CLI_NAME:/tmp/agsafe-provision.php"
PROVISION_JSON="$("${CLI[@]}" eval-file /tmp/agsafe-provision.php)"
echo "$PROVISION_JSON"

BASE_URL="http://localhost:8950"
export PROVISION_JSON BASE_URL CLI_NAME
node -e '
  const fs = require("fs");
  const lines = process.env.PROVISION_JSON.trim().split("\n").filter(Boolean);
  const state = JSON.parse(lines[lines.length - 1]);
  state.baseUrl = process.env.BASE_URL;
  state.cliContainer = process.env.CLI_NAME;
  fs.writeFileSync("state.json", JSON.stringify(state, null, 2));
  console.log("wrote state.json");
'

echo "== done =="
echo "Feature flag, Shop Manager, bound REST key and product are provisioned."
echo "Next: node run.js"
