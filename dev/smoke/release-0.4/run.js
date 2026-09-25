/**
 * release-0.4 proof harness — the Node driver for spec rows P2 (final
 * assertions only) through P8, run IN THIS ORDER: P3, P4, P5, P6, P7, P8.
 * P1 and P2's activation/upgrade mechanics are done by release-0.4.sh in
 * pure `wp`/docker steps; this file verifies P2's outcome (it needs HTTP +
 * SQL helpers already defined here) and then drives every row that needs
 * MCP/HTTP/multi-step polling.
 *
 * Follows dev/smoke/woo-native-mcp/run.js and dev/smoke/smoke-core.js
 * idioms: check()/PASS-FAIL, dbRows()/dbCount() via `wp eval-file` (never
 * `wp db query` — the misreport-on-update/delete gotcha), an mcp() JSON-RPC
 * fetch helper with Connection: close, a wp() exec wrapper filtering
 * Deprecated/Notice/Warning lines, and a final summary + process.exit(1) on
 * any failure. Writes /tmp/agsafe-release-0.4-proof/results.json with one
 * boolean per row for release-0.4.sh to fold into its final table.
 *
 * Run: node run.js   (after release-0.4.sh has written state.json)
 */
const { execSync, execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const STATE_PATH = process.env.AGSAFE_STATE_PATH || '/tmp/agsafe-release-0.4-proof/state.json';
const RESULTS_PATH = process.env.AGSAFE_RESULTS_PATH || '/tmp/agsafe-release-0.4-proof/results.json';
const state = JSON.parse(fs.readFileSync(STATE_PATH, 'utf8'));
const BASE = state.baseUrl;
const CLI = `docker exec --user www-data ${state.cliContainer}`;
const PROTOCOL = '2025-06-18';

const results = [];
const rowResults = {}; // row -> boolean (AND of every check in that row)
let currentRow = null;

function check(name, cond, detail) {
  const pass = !!cond;
  results.push({ name, pass, detail: detail || '', row: currentRow });
  if (currentRow) {
    rowResults[currentRow] = (rowResults[currentRow] === undefined ? true : rowResults[currentRow]) && pass;
  }
  console.log((pass ? 'PASS' : 'FAIL') + '  ' + name + (pass ? '' : '   [' + String(detail).slice(0, 500) + ']'));
}

function section(name) {
  currentRow = name;
  console.log('\n=== ' + name + ' ===');
}

function wp(cmd) {
  return execSync(`${CLI} wp ${cmd}`, { encoding: 'utf8' })
    .split('\n').filter((l) => !/^(Deprecated|Notice|Warning)/.test(l)).join('\n').trim();
}

/**
 * Run a SELECT via `wp eval-file` + $wpdb, NEVER `wp db query` (see
 * query.php's docblock: WP-CLI's db query misclassifies a WHERE clause
 * literal containing "update"/"delete" as DML and reports "Rows affected:
 * -1" instead of returning rows).
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

/** Copies a small inline PHP script into the container's /tmp and returns its container path. */
let tmpScriptSeq = 0;
function copyInlineScript(name, phpSource) {
  const localPath = path.join('/tmp', `agsafe-r04-${name}-${process.pid}-${tmpScriptSeq++}.php`);
  fs.writeFileSync(localPath, phpSource);
  const containerPath = `/tmp/agsafe-${name}.php`;
  execFileSync('docker', ['cp', localPath, `${state.cliContainer}:${containerPath}`]);
  fs.unlinkSync(localPath);
  return containerPath;
}

/** One JSON-RPC POST to an MCP endpoint. Connection: close per the documented keep-alive gotcha. */
async function mcp(url, headers, body, sessionId) {
  const h = Object.assign({
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
    Connection: 'close',
  }, headers);
  if (sessionId) {
    h['Mcp-Session-Id'] = sessionId;
    h['MCP-Protocol-Version'] = PROTOCOL;
  }
  // Retry once on a raw network-level failure (connection refused/reset —
  // a `TypeError` from fetch itself, not an HTTP error status). Observed
  // empirically under this harness's back-to-back request load against a
  // single-worker wp-env container; a real HTTP error response (4xx/5xx)
  // is NOT retried here, only a failure to get any response at all.
  let res;
  try {
    res = await fetch(url, { method: 'POST', headers: h, body: JSON.stringify(body) });
  } catch (e) {
    await new Promise((r) => setTimeout(r, 500));
    res = await fetch(url, { method: 'POST', headers: h, body: JSON.stringify(body) });
  }
  const raw = await res.text();
  let json = null;
  try { json = JSON.parse(raw); } catch (e) { /* SSE or empty */ }
  return { status: res.status, json, raw, headers: Object.fromEntries(res.headers.entries()) };
}

function resultText(callResult) {
  if (!callResult || !Array.isArray(callResult.content)) return '';
  return callResult.content.map((c) => c.text || '').join('\n');
}
function haystack(resp) {
  return (resp.raw || '') + ' ' + resultText(resp.json?.result) + ' ' + JSON.stringify(resp.json?.error || '');
}

const WOO_MCP = BASE + '/wp-json/woocommerce/mcp';
const DEFAULT_MCP = BASE + '/wp-json/mcp/mcp-adapter-default-server';
const wooAuth = (ck, cs) => ({ 'X-MCP-API-Key': `${ck}:${cs}` });
const basicAuth = (user, pass) => ({ Authorization: 'Basic ' + Buffer.from(`${user}:${pass}`).toString('base64') });
const toolFor = (verb) => verb.replace('/', '-');

async function handshake(url, headers) {
  const init = await mcp(url, headers, {
    jsonrpc: '2.0', id: 1, method: 'initialize',
    params: { protocolVersion: PROTOCOL, capabilities: {}, clientInfo: { name: 'agsafe-release-0.4-smoke', version: '0.1.0' } },
  });
  const sessionId = init.headers['mcp-session-id'];
  await mcp(url, headers, { jsonrpc: '2.0', method: 'notifications/initialized' }, sessionId);
  return { init, sessionId };
}

async function toolsList(url, headers, sessionId) {
  const list = await mcp(url, headers, { jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }, sessionId);
  return (list.json?.result?.tools || []).map((t) => t.name);
}

async function provisionFreshProduct(name) {
  const raw = execSync(
    `${CLI} bash -lc "AGSAFE_PRODUCT_NAME='${name}' wp eval-file /tmp/agsafe-provision-woo.php"`,
    { encoding: 'utf8' }
  );
  const line = raw.trim().split('\n').filter(Boolean).pop();
  return JSON.parse(line);
}

// --------------------------------------------------------------------------
// Cookie-jar admin login (Node built-in fetch; no Playwright in this file).
// --------------------------------------------------------------------------
function mergeSetCookie(jar, res) {
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : (res.headers.raw ? res.headers.raw()['set-cookie'] : []);
  const list = raw || [];
  for (const line of list) {
    const pair = line.split(';')[0];
    const eq = pair.indexOf('=');
    if (eq > 0) jar.set(pair.slice(0, eq), pair.slice(eq + 1));
  }
}
function cookieHeader(jar) {
  return Array.from(jar.entries()).map(([k, v]) => `${k}=${v}`).join('; ');
}
async function adminLogin(username, password) {
  const jar = new Map();
  const res = await fetch(BASE + '/wp-login.php', {
    method: 'POST',
    redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ log: username, pwd: password, 'wp-submit': 'Log In', redirect_to: BASE + '/wp-admin/', testcookie: '1' }).toString(),
  });
  mergeSetCookie(jar, res);
  return jar;
}
async function getAuthed(jar, urlPath) {
  const res = await fetch(BASE + urlPath, { headers: { Cookie: cookieHeader(jar) } });
  const body = await res.text();
  mergeSetCookie(jar, res);
  return { status: res.status, body };
}
async function postAuthed(jar, urlPath, form) {
  const res = await fetch(BASE + urlPath, {
    method: 'POST',
    redirect: 'manual',
    headers: { Cookie: cookieHeader(jar), 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(form).toString(),
  });
  const body = await res.text();
  mergeSetCookie(jar, res);
  return { status: res.status, body, location: res.headers.get('location') };
}

/** Scrapes a plain _wpnonce value near a marker (no id suffix — e.g. the shadow/rebind forms). */
/**
 * BUG FOUND AND FIXED (post-review): this used to search an 800-char window
 * on BOTH sides of `marker` and take the FIRST `_wpnonce` match in that
 * window — but every `wp_nonce_field()` in this page's markup is emitted
 * AFTER its form's action/marker field, never before, so a -800..+800
 * window nearly always finds an EARLIER, unrelated form's nonce first (e.g.
 * the Pause form's nonce sits ~140 chars before the shadow form's own
 * marker, 700+ chars before the shadow form's real nonce at +861).
 * Confirmed empirically: this returned the Pause nonce for the shadow-save
 * form, `check_admin_referer()` correctly rejected it, and BOTH the
 * "unconfirmed" and "confirmed" shadow submissions silently no-op'd via
 * `wp_die()` rather than actually reaching `applyShadow()` — explaining why
 * neither P6 assertion ever reflected a real save. Fixed to search FORWARD
 * from the marker only.
 */
function scrapeNonceNear(html, marker) {
  const idx = html.indexOf(marker);
  if (idx === -1) return null;
  const windowText = html.slice(idx, idx + 800);
  const m = windowText.match(/name="_wpnonce" value="([a-f0-9]+)"/);
  return m ? m[1] : null;
}

/** Scrapes the _wpnonce inside the same <form>...</form> block that contains value="<approvalId>" (PendingActionsPage's per-row approve/reject forms). */
function scrapeNonceForApprovalForm(html, approvalId) {
  const marker = `value="${approvalId}"`;
  const idx = html.indexOf(marker);
  if (idx === -1) return null;
  const start = html.lastIndexOf('<form', idx);
  const end = html.indexOf('</form>', idx);
  if (start === -1 || end === -1) return null;
  const block = html.slice(start, end);
  const m = block.match(/name="_wpnonce" value="([a-f0-9]+)"/);
  return m ? m[1] : null;
}

// --------------------------------------------------------------------------
// debug.log delta tracking, one snapshot per row.
// --------------------------------------------------------------------------
function readDebugLog() {
  try {
    return execSync(`${CLI} bash -lc "test -f wp-content/debug.log && cat wp-content/debug.log || true"`, { encoding: 'utf8' });
  } catch (e) {
    return String(e.stdout || '');
  }
}
function debugLogLineCount() {
  return readDebugLog().split('\n').length;
}
function agsafeIssuesSince(startLineCount) {
  const lines = readDebugLog().split('\n');
  const newLines = lines.slice(startLineCount);
  return newLines.filter((l) => /PHP (Warning|Notice|Deprecated)/i.test(l) && /agent-safety|Specflux\\AgentSafety/i.test(l));
}

/**
 * Asserts `pack` is NOT currently in `agsafe_shadow_packs` before a row
 * relies on enforcement (not just a dry-run audit) for its own governed
 * call. Added post-review after a confirmed root cause: P3/P4's own
 * products-delete{force} calls were being correctly evaluated as
 * `approval_required` and then let through as an AUDITED DRY RUN because an
 * EARLIER row's shadow-window seeding happened to cover the same pack this
 * row's credential is bound to (spec §3.3 item 11 / §3.4 — shadow mode is
 * SUPPOSED to do exactly this, so a covered pack is a harness bug, not a
 * plugin defect). This is a precondition check, not a debug.log-style
 * post-hoc check: it fails LOUD and immediately if a future row's seeding
 * ever shadows a pack another row still needs enforced.
 */
function assertPackNotShadowed(pack, tablePrefixArg) {
  const row = dbRows(`SELECT option_value FROM ${tablePrefixArg}options WHERE option_name='agsafe_shadow_packs'`)[0];
  const shadowed = !!row && String(row.option_value).includes(`"${pack}"`);
  check(`precondition: ${pack} is not shadowed before this row's governed call`, !shadowed, JSON.stringify(row));
}

(async () => {
  const tablePrefix = wp('config get table_prefix').trim();
  const auditTable = `${tablePrefix}agsafe_audit_log`;
  const approvalsTable = `${tablePrefix}agsafe_approvals`;
  const grantsTable = `${tablePrefix}agsafe_grants`;

  // =========================================================================
  // P2 — upgrade: schema version, grant row, audit event, chain-intact page.
  // =========================================================================
  section('P2');
  let p2Start = debugLogLineCount();
  const schemaVersion = wp(`option get agsafe_schema_version`).trim();
  check('P2 schema version is 4', schemaVersion === '4', schemaVersion);

  const grantRow = dbRows(`SELECT id FROM ${grantsTable} WHERE correlation_id='agsafe-p2-e2e' LIMIT 1`)[0];
  check('P2 grant row present in agsafe_grants', !!grantRow, JSON.stringify(grantRow));

  const p2PendingRow = state.p2_pending_approval_id
    ? dbRows(`SELECT status FROM ${approvalsTable} WHERE approval_id='${state.p2_pending_approval_id}'`)[0]
    : null;
  const p2ApprovedRow = state.p2_approved_approval_id
    ? dbRows(`SELECT status FROM ${approvalsTable} WHERE approval_id='${state.p2_approved_approval_id}'`)[0]
    : null;
  check('P2 pending approval row survived the upgrade', !!p2PendingRow && p2PendingRow.status === 'pending', JSON.stringify(p2PendingRow));
  check('P2 approved approval row survived the upgrade', !!p2ApprovedRow && p2ApprovedRow.status === 'approved', JSON.stringify(p2ApprovedRow));

  // NOTE: `ability` on this row is the OPTION name (agsafe_site_binding),
  // not the event name — AdminChangeRecorder::append(event, option, input)
  // stores $option as `ability` and $event only inside record_json's
  // "reason" key (confirmed against AdminChangeRecorder.php, WpdbAuditSink.php
  // and AuditRecord::toArray()). Match on record_json, not ability.
  const boundEvent = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='agsafe_site_binding' AND record_json LIKE '%"reason":"environment.bound"%'`);
  check('P2 one environment.bound audit event exists', boundEvent >= 1, `count=${boundEvent}`);

  const adminJar1 = await adminLogin('admin', state.admin_password);
  const chainPage1 = await getAuthed(adminJar1, '/wp-admin/tools.php?page=agent-safety-audit');
  check('P2 audit page shows Chain intact.', chainPage1.body.includes('Chain intact.'), chainPage1.body.slice(0, 300));

  check('P2 debug.log clean', agsafeIssuesSince(p2Start).length === 0, agsafeIssuesSince(p2Start).slice(0, 5).join(' | '));

  // =========================================================================
  // P3 — stale: retry after target changed returns approval_required again
  // with a NEW approval id and the exact §3.11 stale message; old row stale.
  // =========================================================================
  section('P3');
  let p3Start = debugLogLineCount();
  try {
    // Diagnostic breadcrumb (post-review addition): print the exact
    // principal and its pack binding right before use, so a future gating
    // bypass is diagnosable from this run's own log instead of needing a
    // live re-run to investigate.
    const p3BindingRow = dbRows(`SELECT option_value FROM ${tablePrefix}options WHERE option_name='agsafe_pack_bindings'`)[0];
    console.log('P3 diagnostic: wc_key_id=' + state.wc_key_id + ' agsafe_pack_bindings=' + JSON.stringify(p3BindingRow));
    assertPackNotShadowed('woo-default-agent', tablePrefix);

    const p3 = await provisionFreshProduct(`agsafe-p3-${Date.now()}`);
    const ck = state.wc_consumer_key, cs = state.wc_consumer_secret;

    const { sessionId } = await handshake(WOO_MCP, wooAuth(ck, cs));

    const del1 = await mcp(WOO_MCP, wooAuth(ck, cs), {
      jsonrpc: '2.0', id: 3, method: 'tools/call',
      params: { name: toolFor('woocommerce/products-delete'), arguments: { id: p3.product_id, force: true } },
    }, sessionId);
    check('P3 first delete refused (isError)', del1.json?.result?.isError === true, JSON.stringify(del1.json || del1.raw).slice(0, 400));

    const pending1 = dbRows(`SELECT approval_id FROM ${approvalsTable} WHERE verb='woocommerce/products-delete' AND status='pending' ORDER BY id DESC LIMIT 1`)[0];
    check('P3 a pending approval was filed', !!pending1, JSON.stringify(pending1));
    const approvalId1 = pending1?.approval_id;

    // Approve via admin-post, scraping the per-row nonce from Pending Actions.
    const pendingPage = await getAuthed(adminJar1, '/wp-admin/tools.php?page=agent-safety-pending');
    const nonce1 = scrapeNonceForApprovalForm(pendingPage.body, approvalId1);
    check('P3 scraped an approve nonce for the pending row', !!nonce1, pendingPage.body.slice(0, 200));
    const approveRes = await postAuthed(adminJar1, '/wp-admin/admin-post.php', {
      action: 'agsafe_approve_action',
      approval_id: approvalId1,
      _wpnonce: nonce1,
    });
    check('P3 approve admin-post redirected (302/303)', approveRes.status === 302 || approveRes.status === 303, approveRes.status);

    const approvedRow = dbRows(`SELECT status FROM ${approvalsTable} WHERE approval_id='${approvalId1}'`)[0];
    check('P3 approval is now approved', approvedRow?.status === 'approved', JSON.stringify(approvedRow));

    // Edit the product (bumps post_modified, which the state probe fingerprints).
    wp(`post update ${p3.product_id} --post_title="Changed Title"`);

    // Retry with the SAME arguments.
    const del2 = await mcp(WOO_MCP, wooAuth(ck, cs), {
      jsonrpc: '2.0', id: 4, method: 'tools/call',
      params: { name: toolFor('woocommerce/products-delete'), arguments: { id: p3.product_id, force: true } },
    }, sessionId);
    const hay2 = haystack(del2);
    check('P3 retry refused (isError)', del2.json?.result?.isError === true, JSON.stringify(del2.json || del2.raw).slice(0, 400));
    check(
      'P3 retry carries the exact stale message',
      hay2.includes('"woocommerce/products-delete" needs human approval again: the target changed after the earlier approval, so a new request has been filed for review.'),
      hay2.slice(0, 500)
    );

    const staleRow = dbRows(`SELECT status FROM ${approvalsTable} WHERE approval_id='${approvalId1}'`)[0];
    check('P3 OLD approval row is now stale', staleRow?.status === 'stale', JSON.stringify(staleRow));

    const pending2 = dbRows(`SELECT approval_id FROM ${approvalsTable} WHERE verb='woocommerce/products-delete' AND status='pending' ORDER BY id DESC LIMIT 1`)[0];
    check('P3 a NEW pending approval with a different id exists', !!pending2 && pending2.approval_id !== approvalId1, JSON.stringify(pending2));

    const productStatus = wp(`post get ${p3.product_id} --field=status`);
    check('P3 product still exists', productStatus === 'publish', productStatus);
  } catch (e) {
    check('P3 section completed without throwing', false, String(e && e.stack || e));
  }
  check('P3 debug.log clean', agsafeIssuesSince(p3Start).length === 0, agsafeIssuesSince(p3Start).slice(0, 5).join(' | '));

  // =========================================================================
  // P4 — site moved: environment.mismatch audit row, approval voided,
  // shadow emptied, admin notice shown, rebind restores neither.
  // =========================================================================
  section('P4');
  let p4Start = debugLogLineCount();
  let originalHome = null, originalSiteurl = null;
  try {
    // BUG FOUND AND FIXED (post-review): wp-env's own wp-config.php defines
    // WP_HOME/WP_SITEURL as PHP constants (confirmed: `wp config list`
    // shows both as `constant`). WordPress wires `pre_option_home`/
    // `pre_option_siteurl` filters whenever those constants are defined, so
    // `home_url()` — what EnvironmentGuard::currentHost() actually reads —
    // returns the CONSTANT regardless of the `home`/`siteurl` DB options.
    // `wp option update home/siteurl` therefore had NO EFFECT on what the
    // plugin saw (confirmed empirically: home_url() kept returning the
    // ORIGINAL address after updating the option), so no mismatch was ever
    // detected. Fixed to move the site via the constants themselves.
    originalHome = wp('config get WP_HOME').trim();
    originalSiteurl = wp('config get WP_SITEURL').trim();
    assertPackNotShadowed('woo-default-agent', tablePrefix);

    const p4 = await provisionFreshProduct(`agsafe-p4-${Date.now()}`);
    const ck = state.wc_consumer_key, cs = state.wc_consumer_secret;
    const { sessionId } = await handshake(WOO_MCP, wooAuth(ck, cs));

    const del = await mcp(WOO_MCP, wooAuth(ck, cs), {
      jsonrpc: '2.0', id: 5, method: 'tools/call',
      params: { name: toolFor('woocommerce/products-delete'), arguments: { id: p4.product_id, force: true } },
    }, sessionId);
    check('P4 delete call filed a pending approval (isError)', del.json?.result?.isError === true, JSON.stringify(del.json || del.raw).slice(0, 300));

    const pendingRow = dbRows(`SELECT approval_id FROM ${approvalsTable} WHERE verb='woocommerce/products-delete' AND status='pending' ORDER BY id DESC LIMIT 1`)[0];
    const approvalId = pendingRow?.approval_id;
    check('P4 pending approval filed', !!approvalId, JSON.stringify(pendingRow));

    const pendingPage = await getAuthed(adminJar1, '/wp-admin/tools.php?page=agent-safety-pending');
    const nonce = scrapeNonceForApprovalForm(pendingPage.body, approvalId);
    await postAuthed(adminJar1, '/wp-admin/admin-post.php', {
      action: 'agsafe_approve_action',
      approval_id: approvalId,
      _wpnonce: nonce,
    });
    const approvedRow = dbRows(`SELECT status FROM ${approvalsTable} WHERE approval_id='${approvalId}'`)[0];
    check('P4 approval is approved-unclaimed', approvedRow?.status === 'approved', JSON.stringify(approvedRow));

    // Seed a shadow window (real .php temp file + docker cp, never `wp eval`).
    const shadowScript = `<?php\ndefined('ABSPATH') || exit;\nupdate_option('agsafe_shadow_packs', array_merge((array) get_option('agsafe_shadow_packs', []), ['readonly-analyst' => time() + 3600]), false);\n`;
    const shadowPath = copyInlineScript('p4-shadow', shadowScript);
    wp(`eval-file ${shadowPath}`);
    const shadowBefore = dbRows(`SELECT option_value FROM ${tablePrefix}options WHERE option_name='agsafe_shadow_packs'`)[0];
    check('P4 shadow window seeded', !!shadowBefore && String(shadowBefore.option_value).includes('readonly-analyst'), JSON.stringify(shadowBefore));

    // Move the site (via the constants home_url()/site_url() actually
    // honour on this env, not the DB options — see the note above).
    wp(`config set WP_HOME 'http://127.0.0.1:8970' --type=constant`);
    wp(`config set WP_SITEURL 'http://127.0.0.1:8970' --type=constant`);

    // One governed call to trigger EnvironmentGuard::ensureCurrent()'s mismatch path.
    // Note: WOO_MCP/BASE still point at localhost:8970 — the request's HOST
    // is unrelated to home/siteurl, so this call still reaches the site; the
    // MISMATCH is purely home_url() vs the stored binding, evaluated inside
    // the request, not the transport address used to reach it.
    await mcp(WOO_MCP, wooAuth(ck, cs), {
      jsonrpc: '2.0', id: 6, method: 'tools/call',
      params: { name: toolFor('woocommerce/products-list'), arguments: {} },
    }, sessionId);

    // Same correction as the P2 environment.bound check above: match the
    // event name inside record_json's "reason" key, not the `ability` column.
    const mismatchCount = dbCount(`SELECT COUNT(*) FROM ${auditTable} WHERE ability='agsafe_site_binding' AND record_json LIKE '%"reason":"environment.mismatch"%'`);
    check('P4 environment.mismatch audit row exists', mismatchCount >= 1, `count=${mismatchCount}`);

    const voidedRow = dbRows(`SELECT status FROM ${approvalsTable} WHERE approval_id='${approvalId}'`)[0];
    check('P4 approval is now void_environment', voidedRow?.status === 'void_environment', JSON.stringify(voidedRow));

    const shadowAfter = dbRows(`SELECT option_value FROM ${tablePrefix}options WHERE option_name='agsafe_shadow_packs'`)[0];
    const shadowAfterEmpty = !shadowAfter || !String(shadowAfter.option_value).includes('readonly-analyst');
    check('P4 shadow option no longer holds readonly-analyst', shadowAfterEmpty, JSON.stringify(shadowAfter));

    // BUG FOUND AND FIXED (post-review): `adminJar1`'s auth cookie was
    // issued while WP_HOME/WP_SITEURL pointed at localhost:8970; changing
    // those constants to move the site invalidates it (WordPress ties
    // cookie validation to the site's own idea of its address), so reusing
    // it here silently redirected to wp-login.php instead of the packs
    // page. Re-login fresh, now that the site has moved.
    const adminJar2 = await adminLogin('admin', state.admin_password);
    const noticePage = await getAuthed(adminJar2, '/wp-admin/tools.php?page=agent-safety-packs');
    check(
      'P4 mismatch notice text appears',
      // esc_html__() encodes the apostrophe in "site's" as &#039;.
      noticePage.body.replace(/&#039;/g, "'").includes("this site's address changed since its shadow windows, grants and approved-but-unclaimed approvals were authorised"),
      noticePage.body.slice(0, 300)
    );

    // Rebind (no id suffix on this nonce action).
    const rebindNonce = scrapeNonceNear(noticePage.body, 'value="agsafe_rebind_environment"');
    check('P4 scraped a rebind nonce', !!rebindNonce, noticePage.body.slice(0, 200));
    await postAuthed(adminJar2, '/wp-admin/admin-post.php', {
      action: 'agsafe_rebind_environment',
      _wpnonce: rebindNonce,
    });

    const afterRebindApproval = dbRows(`SELECT status FROM ${approvalsTable} WHERE approval_id='${approvalId}'`)[0];
    check('P4 approval STILL void_environment after rebind (not restored)', afterRebindApproval?.status === 'void_environment', JSON.stringify(afterRebindApproval));

    const afterRebindShadow = dbRows(`SELECT option_value FROM ${tablePrefix}options WHERE option_name='agsafe_shadow_packs'`)[0];
    const afterRebindEmpty = !afterRebindShadow || !String(afterRebindShadow.option_value).includes('readonly-analyst');
    check('P4 shadow STILL empty after rebind (not restored)', afterRebindEmpty, JSON.stringify(afterRebindShadow));
  } catch (e) {
    check('P4 section completed without throwing', false, String(e && e.stack || e));
  } finally {
    if (originalHome) wp(`config set WP_HOME '${originalHome}' --type=constant`);
    if (originalSiteurl) wp(`config set WP_SITEURL '${originalSiteurl}' --type=constant`);
  }
  check('P4 debug.log clean', agsafeIssuesSince(p4Start).length === 0, agsafeIssuesSince(p4Start).slice(0, 5).join(' | '));

  // =========================================================================
  // P5 — check-approval on the default mcp-adapter server: pending/
  // approved/used, second-principal byte-identical response, rate limiting.
  // =========================================================================
  section('P5');
  let p5Start = debugLogLineCount();
  try {
    // The pinned mcp-adapter (07c9912) default server does NOT expose one
    // tool per ability — confirmed empirically (tools/list here returns only
    // mcp-adapter-discover-abilities / mcp-adapter-get-ability-info /
    // mcp-adapter-execute-ability) and in source
    // (research/mcp-adapter@07c9912: includes/Servers/DefaultServerFactory.php,
    // includes/Abilities/ExecuteAbilityAbility.php). Every ability — Woo's,
    // Agent Safety's own — is reached through ONE generic tool,
    // `mcp-adapter-execute-ability`, with `{ability_name, parameters}`.
    // `ExecuteAbilityAbility::execute()` calls `$ability->execute($parameters)`
    // directly, and WordPress core's own `WP_Ability::execute()` still runs
    // the ability's permission_callback first — so Agent Safety's
    // `wp_register_ability_args`-wrapped permission_callback still governs
    // this path exactly like any other transport; only the RESPONSE shape
    // differs (a WP_Error collapses to `{success:false, error:<message>}`,
    // losing structured data — the same class of transport limitation
    // dev/smoke/woo-native-mcp/README.md documents for Woo's own bundled
    // mcp-adapter, just via a different mechanism here).
    assertPackNotShadowed('woo-default-agent', tablePrefix);
    const authA = basicAuth('admin', state.admin_app_password);
    const { sessionId: sessA } = await handshake(DEFAULT_MCP, authA);
    const toolsA = await toolsList(DEFAULT_MCP, authA, sessA);
    check('P5 default server exposes mcp-adapter-execute-ability', toolsA.includes('mcp-adapter-execute-ability'), toolsA.join(','));

    async function executeAbility(auth, sess, abilityName, parameters) {
      const res = await mcp(DEFAULT_MCP, auth, {
        jsonrpc: '2.0', id: Math.floor(Math.random() * 1e9), method: 'tools/call',
        params: { name: 'mcp-adapter-execute-ability', arguments: { ability_name: abilityName, parameters } },
      }, sess);
      const text = resultText(res.json?.result);
      let parsed = null;
      try { parsed = JSON.parse(text); } catch (e) { /* leave null, callers fall back to raw text */ }
      return { res, text, parsed };
    }

    // BUG FOUND AND FIXED (post-review): the pinned mcp-adapter default
    // server's `execute-ability` reads from WordPress core's OWN Abilities
    // API registry (`wp_get_abilities()`), which WooCommerce 11.1 populates
    // under SINGULAR ids (`woocommerce/product-delete`) — a DIFFERENT
    // registry from the PLURAL ids (`woocommerce/products-delete`) Woo's
    // own deprecated `/wp-json/woocommerce/mcp` transport exposes (verified
    // live: `wp_get_abilities()` lists `product-delete`/`product-update`,
    // never `products-delete`; calling execute-ability with the plural name
    // returned "Ability 'woocommerce/products-delete' not found"). P3/P4
    // correctly keep the plural name (they go through Woo's OWN transport);
    // this row must use the singular one everywhere it talks to
    // execute-ability, including the retry below.
    const p5 = await provisionFreshProduct(`agsafe-p5-${Date.now()}`);
    const del = await executeAbility(authA, sessA, 'woocommerce/product-delete', { id: p5.product_id, force: true });
    // BUG FOUND AND FIXED (post-review): confirmed live that mcp-adapter
    // surfaces a DENIED execute-ability call (WP_Error from the target
    // ability's own permission_callback) as PLAIN TEXT content — the bare
    // denial message, not the `{success:false,error:<msg>}` JSON shape
    // `ExecuteAbilityAbility`'s own output_schema documents for a
    // successfully-returned failure. `del.parsed` is therefore null here
    // (JSON.parse legitimately fails on plain English text) by construction,
    // not a bug in the ability itself — check the raw text instead, the same
    // way every other row in this file already does.
    check('P5 delete via execute-ability filed a pending approval', del.text.includes('needs human approval before it can run'), del.text.slice(0, 300));

    const pendingRow = dbRows(`SELECT approval_id FROM ${approvalsTable} WHERE verb='woocommerce/product-delete' AND status='pending' ORDER BY id DESC LIMIT 1`)[0];
    const realApprovalId = pendingRow?.approval_id;
    check('P5 pending approval id obtained', !!realApprovalId, JSON.stringify(pendingRow));

    if (realApprovalId) {
      const pollPending = await executeAbility(authA, sessA, 'agent-safety/check-approval', { approval_id: realApprovalId });
      check('P5 poll while pending: status pending', pollPending.parsed?.data?.status === 'pending', pollPending.text.slice(0, 300));
      check(
        'P5 poll while pending: next_action matches §3.11',
        pollPending.parsed?.data?.next_action === 'Waiting for a human. Check again in 30 seconds or more, and stop at pending_expires_at.',
        pollPending.text.slice(0, 300)
      );

      const pendingPage = await getAuthed(adminJar1, '/wp-admin/tools.php?page=agent-safety-pending');
      const nonce = scrapeNonceForApprovalForm(pendingPage.body, realApprovalId);
      await postAuthed(adminJar1, '/wp-admin/admin-post.php', { action: 'agsafe_approve_action', approval_id: realApprovalId, _wpnonce: nonce });

      const pollApproved = await executeAbility(authA, sessA, 'agent-safety/check-approval', { approval_id: realApprovalId });
      check('P5 poll after approve: status approved', pollApproved.parsed?.data?.status === 'approved', pollApproved.text.slice(0, 300));
      check(
        'P5 poll after approve: next_action matches §3.11',
        pollApproved.parsed?.data?.next_action === 'Retry the original call now, with exactly the same arguments.',
        pollApproved.text.slice(0, 300)
      );

      // Retry the ORIGINAL delete through the SAME transport/verb that
      // created the approval (execute-ability + the singular ability id) —
      // retrying via Woo's own plural-named endpoint would check/consume a
      // DIFFERENT verb's approval row entirely.
      await executeAbility(authA, sessA, 'woocommerce/product-delete', { id: p5.product_id, force: true });

      const pollUsed = await executeAbility(authA, sessA, 'agent-safety/check-approval', { approval_id: realApprovalId });
      check('P5 poll after retry: status used', pollUsed.parsed?.data?.status === 'used', pollUsed.text.slice(0, 300));
      check(
        'P5 poll after retry: next_action matches §3.11',
        pollUsed.parsed?.data?.next_action === 'This approval has already been used. Another call needs a new approval.',
        pollUsed.text.slice(0, 300)
      );
    } else {
      console.log('SKIP: P5 pending/approved/used poll assertions (no pending approval id obtained).');
    }

    // Second principal: a brand new WP user + application password. The Self
    // integration governs `agent-safety/*` at tier 0 unconditionally (§3.5
    // item 3), so no pack binding is needed for principal B to CALL
    // check-approval — only the row-scoping check inside the ability cares
    // whether principal B's resolved identity equals the row's key_id.
    const secondUsername = 'agsafe_second_principal';
    if (!wp(`user list --field=user_login`).split('\n').includes(secondUsername)) {
      wp(`user create ${secondUsername} second@example.test --role=subscriber --porcelain`);
    }
    const secondAppPassword = wp(`user application-password create ${secondUsername} p5-second --porcelain`).trim();
    const authB = basicAuth(secondUsername, secondAppPassword);
    const { sessionId: sessB } = await handshake(DEFAULT_MCP, authB);

    const unknownId1 = 'apr_doesnotexist000000000000';
    const unknownId2 = 'apr_zz00000000000000';

    if (realApprovalId) {
      const pollAsB = await executeAbility(authB, sessB, 'agent-safety/check-approval', { approval_id: realApprovalId });
      const pollUnknownAsB = await executeAbility(authB, sessB, 'agent-safety/check-approval', { approval_id: unknownId2 });
      check(
        'P5 second principal body byte-identical to an unknown id response',
        pollAsB.text === pollUnknownAsB.text,
        JSON.stringify({ bodyReal: pollAsB.text, bodyUnknown: pollUnknownAsB.text }).slice(0, 400)
      );
    } else {
      console.log('SKIP: P5 byte-identical comparison (no real approval id available).');
    }

    // Rate limit: 11 rapid polls in one minute against a known-unknown id.
    let lastRate = null;
    for (let i = 0; i < 11; i++) {
      lastRate = await executeAbility(authA, sessA, 'agent-safety/check-approval', { approval_id: unknownId1 });
    }
    check('P5 11th poll in a minute is rate_limited', lastRate?.parsed?.data?.status === 'rate_limited', String(lastRate?.text).slice(0, 300));
    check('P5 rate_limited response carries retry_after', typeof lastRate?.parsed?.data?.retry_after !== 'undefined', String(lastRate?.text).slice(0, 300));
  } catch (e) {
    check('P5 section completed without throwing', false, String(e && e.stack || e));
  }
  check('P5 debug.log clean', agsafeIssuesSince(p5Start).length === 0, agsafeIssuesSince(p5Start).slice(0, 5).join(' | '));

  // =========================================================================
  // P6 — production shadow via the real admin HTTP form.
  // =========================================================================
  section('P6');
  let p6Start = debugLogLineCount();
  try {
    // Clear any shadow state left by P2 (grant/shadow seeding) or P4 (its own
    // shadow window) before this row's own test. CapabilityPacksPage::
    // applyShadow() treats the posted `shadow[]` set as the FULL desired
    // ticked state — a submission that doesn't re-list an already-shadowed
    // pack DISABLES it as a side effect (`ShadowMode::apply()`), so residue
    // from an earlier row corrupts this row's own before/after assertions
    // (confirmed empirically: without this reset, the earlier row's leftover
    // shadow entry gets silently wiped or renewed depending on which pack the
    // test happens to pick, not the pack THIS row actually submits). Reset to
    // a known-empty baseline via the same option this class itself documents.
    const clearShadowScript = `<?php\ndefined('ABSPATH') || exit;\ndelete_option('agsafe_shadow_packs');\n`;
    wp(`eval-file ${copyInlineScript('p6-clear-shadow', clearShadowScript)}`);

    const envTypeScript = `<?php\ndefined('ABSPATH') || exit;\necho function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'unknown';\n`;
    const envTypePath = copyInlineScript('p6-envtype', envTypeScript);
    const envType = wp(`eval-file ${envTypePath}`).trim();
    check('P6 WP_ENVIRONMENT_TYPE unset defaults to production', envType === 'production', envType);

    const packsPage = await getAuthed(adminJar1, '/wp-admin/tools.php?page=agent-safety-packs');
    const shadowNonce1 = scrapeNonceNear(packsPage.body, 'value="agsafe_save_shadow_packs"');
    check('P6 scraped a shadow-save nonce', !!shadowNonce1, packsPage.body.slice(0, 200));

    const packNames = [...packsPage.body.matchAll(/name="shadow\[\]" value="([^"]+)"/g)].map((m) => m[1]);
    check('P6 packs page lists at least one pack', packNames.length > 0, packNames.join(','));

    const shadowOptionRow = () => dbRows(`SELECT option_value FROM ${tablePrefix}options WHERE option_name='agsafe_shadow_packs'`)[0];
    const isShadowed = (name) => {
      const row = shadowOptionRow();
      return !!row && String(row.option_value).includes(`"${name}"`);
    };
    const pack = packNames.find((n) => !isShadowed(n)) || packNames[0];
    check('P6 chose an unshadowed pack for the test', !!pack, packNames.join(','));

    // 1. Submit WITHOUT the typed confirmation.
    await postAuthed(adminJar1, '/wp-admin/admin-post.php', {
      action: 'agsafe_save_shadow_packs',
      _wpnonce: shadowNonce1,
      'shadow[]': pack,
      shadow_days: '1',
    });
    check('P6 unconfirmed submit does NOT shadow the pack', !isShadowed(pack), JSON.stringify(shadowOptionRow()));

    // 2. Submit WITH the typed confirmation (fresh nonce).
    const packsPage2 = await getAuthed(adminJar1, '/wp-admin/tools.php?page=agent-safety-packs');
    const shadowNonce2 = scrapeNonceNear(packsPage2.body, 'value="agsafe_save_shadow_packs"');
    const before = Math.floor(Date.now() / 1000);
    await postAuthed(adminJar1, '/wp-admin/admin-post.php', {
      action: 'agsafe_save_shadow_packs',
      _wpnonce: shadowNonce2,
      'shadow[]': pack,
      [`shadow_confirm[${pack}]`]: pack,
      shadow_days: '1',
    });
    const after = Math.floor(Date.now() / 1000);

    const row = shadowOptionRow();
    check('P6 confirmed submit DOES shadow the pack', isShadowed(pack), JSON.stringify(row));

    const decoded = row ? phpUnserializeOrJson(row.option_value) : null;
    const expiry = decoded && typeof decoded === 'object' ? decoded[pack] : null;
    check('P6 stored expiry is within 24h + 5s slack and in the future', typeof expiry === 'number' && expiry > before && expiry <= after + 86400 + 5, JSON.stringify({ expiry, before, after }));
  } catch (e) {
    check('P6 section completed without throwing', false, String(e && e.stack || e));
  }
  check('P6 debug.log clean', agsafeIssuesSince(p6Start).length === 0, agsafeIssuesSince(p6Start).slice(0, 5).join(' | '));

  // =========================================================================
  // P7 — privacy: exporter/eraser called directly, audit page chain intact.
  // =========================================================================
  section('P7');
  let p7Start = debugLogLineCount();
  try {
    const shopManagerEmail = state.shop_manager_email || 'agsafe_shop_manager@example.test';

    // Drive at least one governed call for the Shop Manager so there is an
    // audit row to retain, in case nothing earlier in the run left one.
    // (Fixed: this was firing a bare tools/call with no prior
    // initialize/notifications-initialized handshake and no session id —
    // every other section in this file handshakes first.)
    const { sessionId: p7Session } = await handshake(WOO_MCP, wooAuth(state.wc_consumer_key, state.wc_consumer_secret));
    await mcp(WOO_MCP, wooAuth(state.wc_consumer_key, state.wc_consumer_secret), {
      jsonrpc: '2.0', id: 1, method: 'tools/call',
      params: { name: toolFor('woocommerce/products-list'), arguments: {} },
    }, p7Session);

    const privacyScript = `<?php
defined('ABSPATH') || exit;
global \$wpdb;
\$reader = new \\Specflux\\AgentSafety\\Plugin\\Privacy\\PrivacyAuditReader(\$wpdb);
\$exporter = new \\Specflux\\AgentSafety\\Plugin\\Privacy\\PersonalDataExporter(\$reader);
\$eraser = new \\Specflux\\AgentSafety\\Plugin\\Privacy\\PersonalDataEraser(\$reader);
\$email = getenv('AGSAFE_PRIVACY_EMAIL');
\$exportResult = \$exporter->export(\$email, 1);
\$eraseResult = \$eraser->erase(\$email, 1);
echo json_encode(['export' => \$exportResult, 'erase' => \$eraseResult]) . "\\n";
`;
    const privacyPath = copyInlineScript('p7-privacy', privacyScript);
    const raw = execSync(
      `${CLI} bash -lc "AGSAFE_PRIVACY_EMAIL='${shopManagerEmail}' wp eval-file ${privacyPath}"`,
      { encoding: 'utf8' }
    );
    const line = raw.trim().split('\n').filter(Boolean).pop();
    const privacy = JSON.parse(line);

    check('P7 exporter returned at least one data group', Array.isArray(privacy.export?.data) && privacy.export.data.length > 0, JSON.stringify(privacy.export).slice(0, 300));
    check('P7 eraser reports items_retained', privacy.erase?.items_retained === true, JSON.stringify(privacy.erase));
    check(
      'P7 eraser message mentions tamper-evident',
      (privacy.erase?.messages || []).some((m) => /tamper-evident/i.test(m)),
      JSON.stringify(privacy.erase?.messages)
    );

    const auditPage = await getAuthed(adminJar1, '/wp-admin/tools.php?page=agent-safety-audit');
    check('P7 audit page still shows Chain intact.', auditPage.body.includes('Chain intact.'), auditPage.body.slice(0, 300));
  } catch (e) {
    check('P7 section completed without throwing', false, String(e && e.stack || e));
  }
  check('P7 debug.log clean', agsafeIssuesSince(p7Start).length === 0, agsafeIssuesSince(p7Start).slice(0, 5).join(' | '));

  // =========================================================================
  // P8 — uninstall: no agsafe_% options, no agsafe_% tables, no cron, no
  // transients. Run near the end because it deletes all Agent Safety data.
  // =========================================================================
  section('P8');
  let p8Start = debugLogLineCount();
  try {
    wp('config set AGSAFE_REMOVE_DATA true --raw');
    wp('plugin uninstall agent-safety --deactivate');

    const optionsLeft = dbCount(`SELECT COUNT(*) FROM ${tablePrefix}options WHERE option_name LIKE 'agsafe%'`);
    check('P8 no agsafe_% options remain', optionsLeft === 0, `count=${optionsLeft}`);

    const tablesLeft = dbCount(`SHOW TABLES LIKE '${tablePrefix}agsafe%'`);
    check('P8 no agsafe_% tables remain', tablesLeft === 0, `count=${tablesLeft}`);

    const cronRaw = wp('cron event list --format=json');
    let cronEvents = [];
    try { cronEvents = JSON.parse(cronRaw); } catch (e) { /* leave empty on parse issues */ }
    const agsafeCron = cronEvents.filter((e) => /agsafe|agent[-_]safety/i.test(e.hook || ''));
    check('P8 no Agent Safety cron event remains', agsafeCron.length === 0, JSON.stringify(agsafeCron));

    const transientsLeft = dbCount(`SELECT COUNT(*) FROM ${tablePrefix}options WHERE option_name LIKE '%transient_agsafe%'`);
    check('P8 no _transient_agsafe_% option rows remain', transientsLeft === 0, `count=${transientsLeft}`);
  } catch (e) {
    check('P8 section completed without throwing', false, String(e && e.stack || e));
  }
  check('P8 debug.log clean', agsafeIssuesSince(p8Start).length === 0, agsafeIssuesSince(p8Start).slice(0, 5).join(' | '));

  // --------------------------------------------------------------------------
  const failed = results.filter((r) => !r.pass);
  console.log(`\n=== ${results.length - failed.length}/${results.length} checks passed ===`);

  fs.writeFileSync(RESULTS_PATH, JSON.stringify(rowResults, null, 2));
  console.log('wrote ' + RESULTS_PATH + ': ' + JSON.stringify(rowResults));

  if (failed.length) process.exit(1);
})().catch((e) => {
  console.error('SMOKE CRASH:', e);
  try { fs.writeFileSync(RESULTS_PATH, JSON.stringify(rowResults, null, 2)); } catch (e2) { /* best effort */ }
  process.exit(2);
});

/** Best-effort decode of a WordPress option value that may be PHP-serialized or JSON. */
function phpUnserializeOrJson(value) {
  if (typeof value !== 'string') return null;
  try {
    return JSON.parse(value);
  } catch (e) {
    // PHP serialized array of string => int, e.g. a:1:{s:16:"woo-default-agent";i:1234567890;}
    const out = {};
    const re = /s:\d+:"([^"]*)";i:(\d+);/g;
    let m;
    while ((m = re.exec(value)) !== null) {
      out[m[1]] = parseInt(m[2], 10);
    }
    return out;
  }
}
