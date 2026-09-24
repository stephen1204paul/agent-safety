/**
 * Stage 3 wp-env leg: Agent Safety governing WooCommerce 11.1's OWN native
 * MCP server (`/wp-json/woocommerce/mcp`, Woo's bundled mcp-adapter v0.3.0,
 * `mcp_integration` feature on). See spec §3.2 item 5 and README.md.
 *
 * Cases (as a Shop Manager's bound `wc:<key_id>` REST key, NOT an admin):
 *   1. woocommerce/products-list  -> allowed, returns products
 *   2. woocommerce/products-delete {id, force:true} -> isError, an approval
 *      message for the verb, and the product still exists afterwards; the
 *      approval_id itself is asserted as DB ground truth (a pending
 *      wp_agsafe_approvals row keyed to wc:<key_id>), not off the MCP
 *      response — Woo's bundled mcp-adapter v0.3.0 drops WP_Error data on
 *      this transport (README's "Live findings", finding 2)
 *   3. the fixture's uncatalogued woocommerce/ ability -> unknown_verb
 *   Control: the audit rows for 1 and 2 name principal wc:<key_id> and pack
 *   woo-default-agent, not the fallback default-agent.
 *   Plus: debug.log has no PHP warning/notice from agent-safety.
 *
 * Run: node run.js   (after ./setup.sh has written state.json)
 */
const { execSync, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const DIR = __dirname;
const state = JSON.parse(fs.readFileSync(path.join(DIR, 'state.json'), 'utf8'));
const BASE = state.baseUrl;
const CLI = `docker exec --user www-data ${state.cliContainer}`;
const MCP = BASE + '/wp-json/woocommerce/mcp';
const AUTH = `${state.consumer_key}:${state.consumer_secret}`;
const PROTOCOL = '2025-06-18';

const results = [];
function check(name, cond, detail) {
  results.push({ name, pass: !!cond, detail: detail || '' });
  console.log((cond ? 'PASS' : 'FAIL') + '  ' + name + (cond ? '' : '   [' + String(detail).slice(0, 500) + ']'));
}

function wp(cmd) {
  return execSync(`${CLI} wp ${cmd}`, { encoding: 'utf8' })
    .split('\n').filter((l) => !/^(Deprecated|Notice|Warning)/.test(l)).join('\n').trim();
}

/**
 * Run a SELECT against the audit log via a `wp eval-file` + $wpdb, NOT
 * `wp db query`: WP-CLI's db query decides SELECT-vs-DML by scanning the
 * whole query text for keywords like "update"/"delete" ANYWHERE in it, so a
 * WHERE clause literal such as 'woocommerce/products-delete' gets
 * misclassified and WP-CLI prints "Rows affected: -1" instead of returning
 * rows (see query.php's docblock). Passed via -e (argv, not a shell string)
 * so the query's own quotes/slashes never touch shell parsing.
 *
 * @returns {Array<Object>} rows as plain objects.
 */
function dbRows(query) {
  const raw = execFileSync(
    'docker',
    ['exec', '-e', `AGSAFE_SQL=${query}`, '--user', 'www-data', state.cliContainer, 'wp', 'eval-file', '/tmp/agsafe-query.php'],
    { encoding: 'utf8' }
  );
  const line = raw.trim().split('\n').filter(Boolean).pop() || '[]';
  return JSON.parse(line);
}
function dbCount(query) {
  const rows = dbRows(query);
  return rows.length > 0 ? parseInt(Object.values(rows[0])[0], 10) : 0;
}

/**
 * One JSON-RPC POST to Woo's own MCP endpoint. `Connection: close` (a fresh
 * TCP connection every call, no keep-alive reuse): observed empirically that
 * Node's undici fetch can lose a pooled keep-alive connection after
 * products-list's large response, surfacing as "other side closed" on the
 * NEXT request — not an Agent Safety behaviour, a client/local-Apache
 * keep-alive quirk in this harness.
 */
async function mcp(body, sessionId) {
  const headers = {
    'X-MCP-API-Key': AUTH,
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
    Connection: 'close',
  };
  if (sessionId) {
    headers['Mcp-Session-Id'] = sessionId;
    headers['MCP-Protocol-Version'] = PROTOCOL;
  }
  const res = await fetch(MCP, { method: 'POST', headers, body: JSON.stringify(body) });
  const raw = await res.text();
  let json = null;
  try { json = JSON.parse(raw); } catch (e) { /* SSE or empty */ }
  return { status: res.status, json, raw, headers: Object.fromEntries(res.headers.entries()) };
}

function resultText(callResult) {
  if (!callResult || !Array.isArray(callResult.content)) return '';
  return callResult.content.map((c) => c.text || '').join('\n');
}

/** Every string this response could plausibly carry `needle` in — payload
 * shape differs across mcp-adapter versions, so check broadly rather than
 * assume one shape. */
function haystack(resp) {
  return (resp.raw || '') + ' ' + resultText(resp.json?.result) + ' ' + JSON.stringify(resp.json?.error || '');
}

(async () => {
  if (!state.consumer_key || !state.consumer_secret) {
    console.error('state.json is missing consumer_key/consumer_secret — run setup.sh first.');
    process.exit(2);
  }

  const tablePrefix = wp('config get table_prefix').trim();
  const auditTable = `${tablePrefix}agsafe_audit_log`;

  // ---------- MCP handshake ----------
  const init = await mcp({
    jsonrpc: '2.0', id: 1, method: 'initialize',
    params: { protocolVersion: PROTOCOL, capabilities: {}, clientInfo: { name: 'agsafe-woo-mcp-smoke', version: '0.1.0' } },
  });
  check('MCP initialize accepted', init.status === 200 && init.json && !init.json.error, JSON.stringify(init.json || init.raw).slice(0, 300));
  const sessionId = init.headers['mcp-session-id'];
  check('MCP session id issued', !!sessionId, JSON.stringify(init.headers));

  await mcp({ jsonrpc: '2.0', method: 'notifications/initialized' }, sessionId);

  const list = await mcp({ jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }, sessionId);
  const toolNames = (list.json?.result?.tools || []).map((t) => t.name);
  console.log('tools/list ->', toolNames.join(', '));

  // Woo's tool-naming convention (verified against 11.1.0: only the FIRST
  // hyphen is the namespace boundary — see plugin/src/Integrations/Woo/VerbMapper.php),
  // confirmed against the live tools/list above rather than assumed.
  const toolFor = (verb) => verb.replace('/', '-');
  const need = {
    productsList: toolFor('woocommerce/products-list'),
    productsDelete: toolFor('woocommerce/products-delete'),
    uncatalogued: toolFor('woocommerce/agsafe-smoke-widget-list'),
  };
  for (const [k, name] of Object.entries(need)) {
    check(`tools/list exposes ${name}`, toolNames.includes(name), toolNames.join(','));
  }

  // ---------- Case 1: products-list allowed ----------
  const before1 = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='woocommerce/products-list' AND decision='allowed'`);
  const listRes = await mcp({
    jsonrpc: '2.0', id: 3, method: 'tools/call',
    params: { name: need.productsList, arguments: {} },
  }, sessionId);
  const listOk = listRes.json?.result && !listRes.json.result.isError;
  check('woocommerce-products-list executes without error', listOk, JSON.stringify(listRes.json || listRes.raw).slice(0, 400));
  const listText = resultText(listRes.json?.result);
  check('products-list response mentions the seeded product', listText.includes('AgSafe Stage 3 Widget') || listText.includes(String(state.product_id)), listText.slice(0, 400));
  const after1 = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='woocommerce/products-list' AND decision='allowed'`);
  check('audit row(s) allowed for woocommerce/products-list', after1 > before1, `before=${before1} after=${after1}`);

  // Control 1: principal + pack on that audit row.
  const row1 = dbRows(`SELECT pack, record_json FROM ${auditTable} WHERE ability='woocommerce/products-list' AND decision='allowed' ORDER BY id DESC LIMIT 1`)[0] || {};
  check('case 1 audit row pack is woo-default-agent', row1.pack === 'woo-default-agent', JSON.stringify(row1).slice(0, 300));
  check('case 1 audit row principal is wc:<key_id>', (row1.record_json || '').includes(`"token_id":"wc:${state.key_id}"`), row1.record_json);
  check('case 1 audit row principal is NOT default-agent', row1.pack !== 'default-agent', JSON.stringify(row1).slice(0, 300));

  // ---------- Case 2: products-delete {force:true} -> approval_required ----------
  const before2 = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='woocommerce/products-delete' AND decision='pending'`);
  const delRes = await mcp({
    jsonrpc: '2.0', id: 4, method: 'tools/call',
    params: { name: need.productsDelete, arguments: { id: state.product_id, force: true } },
  }, sessionId);
  const delHay = haystack(delRes);
  // Woo's bundled mcp-adapter v0.3.0 collapses a permission_callback WP_Error
  // into a generic "Permission denied: <message>" CallToolResult, keeping
  // Verdict::error()'s tier-neutral English message but NOT its structured
  // WP_Error code/data (status/verb/tier/approval_id) — see README's
  // "Live findings" section (finding 2, a documented transport limitation,
  // not an Agent Safety defect). On THIS transport we therefore assert only
  // what actually crosses it: isError, the approval message text, that the
  // product survives, and DB ground truth for the filed approval + audit row.
  check('products-delete{force} call is refused (isError)', delRes.json?.result?.isError === true, JSON.stringify(delRes.json || delRes.raw).slice(0, 500));

  check(
    'response text carries the approval message for the verb',
    delHay.includes('"woocommerce/products-delete" needs human approval before it can run. A request has been filed for review.'),
    delHay.slice(0, 500),
  );

  const productStatus = wp(`post get ${state.product_id} --field=status`);
  check('product still exists after the approval-required call', productStatus === 'publish', productStatus);

  const after2 = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='woocommerce/products-delete' AND decision='pending'`);
  check('audit row(s) pending for woocommerce/products-delete', after2 > before2, `before=${before2} after=${after2}`);
  const pendingApproval = dbRows(`SELECT approval_id, key_id FROM ${tablePrefix}agsafe_approvals WHERE verb='woocommerce/products-delete' AND status='pending' ORDER BY id DESC LIMIT 1`)[0];
  check('a pending Approval row was actually filed (DB ground truth)', !!pendingApproval, JSON.stringify(pendingApproval));
  check('the pending Approval row\'s key_id is wc:<key_id>', pendingApproval?.key_id === `wc:${state.key_id}`, JSON.stringify(pendingApproval));

  // Control 2: principal + pack on that audit row. The principal check now
  // PASSES because of Fix A (RequestContext::tokenId() names the token that
  // won the pack binding, not merely the first token in identity-chain order).
  const row2 = dbRows(`SELECT pack, record_json FROM ${auditTable} WHERE ability='woocommerce/products-delete' AND decision='pending' ORDER BY id DESC LIMIT 1`)[0] || {};
  check('case 2 audit row pack is woo-default-agent', row2.pack === 'woo-default-agent', JSON.stringify(row2).slice(0, 300));
  check('case 2 audit row principal is wc:<key_id>', (row2.record_json || '').includes(`"token_id":"wc:${state.key_id}"`), row2.record_json);
  check('case 2 audit row principal is NOT default-agent', row2.pack !== 'default-agent', JSON.stringify(row2).slice(0, 300));
  check('case 2 audit row reason is approval_required', (row2.record_json || '').includes('"reason":"approval_required"'), row2.record_json);

  // ---------- Case 3: uncatalogued woocommerce/ ability -> unknown_verb ----------
  const before3 = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='woocommerce/agsafe-smoke-widget-list'`);
  const uncatRes = await mcp({
    jsonrpc: '2.0', id: 5, method: 'tools/call',
    params: { name: need.uncatalogued, arguments: {} },
  }, sessionId);
  const uncatHay = haystack(uncatRes);
  check('uncatalogued ability refused (isError)', uncatRes.json?.result?.isError === true, uncatHay.slice(0, 500));
  const after3 = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='woocommerce/agsafe-smoke-widget-list'`);
  check('audit row written for the uncatalogued attempt', after3 > before3, `before=${before3} after=${after3}`);
  const denyReason = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='woocommerce/agsafe-smoke-widget-list' AND decision='denied' AND record_json LIKE '%unknown_verb%'`);
  check('denial reason recorded is unknown_verb', denyReason >= 1, `rows=${denyReason}`);

  // ---------- debug.log: no PHP warning/notice from agent-safety ----------
  let debugLog = '';
  try {
    debugLog = execSync(`${CLI} bash -lc "test -f wp-content/debug.log && cat wp-content/debug.log || true"`, { encoding: 'utf8' });
  } catch (e) {
    debugLog = String(e.stdout || '');
  }
  const agsafeIssues = debugLog.split('\n').filter((l) => /PHP (Warning|Notice|Deprecated)/i.test(l) && /agent-safety|Specflux\\AgentSafety/i.test(l));
  check('debug.log has no PHP warning/notice/deprecated from agent-safety', agsafeIssues.length === 0, agsafeIssues.slice(0, 5).join(' | '));

  const failed = results.filter((r) => !r.pass);
  console.log(`\n=== ${results.length - failed.length}/${results.length} checks passed ===`);
  if (failed.length) process.exit(1);
})().catch((e) => { console.error('SMOKE CRASH:', e); process.exit(2); });
