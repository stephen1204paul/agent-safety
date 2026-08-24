/**
 * Live wp-env smoke for Agent Safety 0.3 stage 4 — the WordPress-core module,
 * Woo OFF. Drives mcp-adapter's HTTP transport end to end:
 *
 *   1. tools/call core-get-site-info  -> 200, audit row allowed / core/get-site-info
 *   2. tools/call core-nope           -> denied, audit reason unknown_verb
 *      (governed namespace, deliberately unmapped verb: D23 fail-closed)
 *   3. tools/call core-get-user-info  -> no UNMASKED user PII in the response
 *      (D26 read-path redaction of user_email / user_pass / user_activation_key)
 *   4. Audit Log page reports "Chain intact" after all of the above.
 *
 * Prereqs (reset-core.sh): wp-env running from the planning dir; an admin app
 * password bound to the site-readonly pack via agsafe_pack_bindings.
 *
 * Run: AGSAFE_SMOKE_APP_PASSWORD=xxxx node smoke-core.js
 */
const { chromium, request } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');

const BASE = process.env.AGSAFE_SMOKE_BASE || 'http://localhost:8888';
// The wp-env CLI container name embeds an unstable content hash — discover it.
const CLI = process.env.AGSAFE_SMOKE_CLI || `docker exec --user www-data ${
  execSync("docker ps --format '{{.Names}}'", { encoding: 'utf8' })
    .split('\n').find((n) => n.trim().endsWith('-cli-1') && !n.includes('tests')).trim()
}`;
const MCP = BASE + '/wp-json/mcp/mcp-adapter-default-server';
const APP_PASSWORD = process.env.AGSAFE_SMOKE_APP_PASSWORD || '';
const ADMIN_EMAIL = process.env.AGSAFE_SMOKE_ADMIN_EMAIL || 'wordpress@admin';
const PROTOCOL = '2025-06-18';
const SHOTS = __dirname + '/shots';
fs.mkdirSync(SHOTS, { recursive: true });

const results = [];
function check(name, cond, detail) {
  results.push({ name, pass: !!cond, detail: detail || '' });
  console.log((cond ? 'PASS' : 'FAIL') + '  ' + name + (cond ? '' : '   [' + String(detail).slice(0, 300) + ']'));
}

function wp(cmd) {
  return execSync(`${CLI} wp ${cmd}`, { encoding: 'utf8' })
    .split('\n').filter(l => !/^(Deprecated|Notice|Warning)/.test(l)).join('\n').trim();
}
function sql(q) {
  return wp(`db query ${JSON.stringify(q)} --skip-column-names`);
}
function evalPhp(code) {
  return wp(`eval ${JSON.stringify(code)}`);
}

/** One JSON-RPC POST to the MCP endpoint. */
async function mcp(ctx, auth, body, sessionId) {
  const headers = {
    Authorization: auth,
    'Content-Type': 'application/json',
    Accept: 'application/json, text/event-stream',
  };
  if (sessionId) {
    headers['Mcp-Session-Id'] = sessionId;
    headers['MCP-Protocol-Version'] = PROTOCOL;
  }
  const res = await ctx.post(MCP, { headers, data: JSON.stringify(body) });
  let json = null;
  try { json = await res.json(); } catch (e) { /* GET/SSE or empty body */ }
  return { status: res.status(), json, headers: res.headers() };
}

/** Flatten a CallToolResult's text content into one string. */
function resultText(callResult) {
  if (!callResult || !Array.isArray(callResult.content)) return '';
  return callResult.content.map((c) => c.text || '').join('\n');
}

(async () => {
  if (!APP_PASSWORD) {
    console.error('Set AGSAFE_SMOKE_APP_PASSWORD (see reset-core.sh step 4)');
    process.exit(2);
  }
  const AUTH = 'Basic ' + Buffer.from('admin:' + APP_PASSWORD).toString('base64');
  const ctx = await request.newContext();

  // ---------- MCP handshake ----------
  const init = await mcp(ctx, AUTH, {
    jsonrpc: '2.0', id: 1, method: 'initialize',
    params: { protocolVersion: PROTOCOL, capabilities: {}, clientInfo: { name: 'agsafe-smoke', version: '0.1.0' } },
  });
  check('MCP initialize accepted', init.status === 200 && init.json && !init.json.error, JSON.stringify(init.json).slice(0, 200));
  const sessionId = init.headers['mcp-session-id'];
  check('MCP session id issued', !!sessionId, JSON.stringify(init.headers));

  await mcp(ctx, AUTH, { jsonrpc: '2.0', method: 'notifications/initialized' }, sessionId);

  const list = await mcp(ctx, AUTH, { jsonrpc: '2.0', id: 2, method: 'tools/list', params: {} }, sessionId);
  const toolNames = (list.json?.result?.tools || []).map((t) => t.name);
  for (const t of ['core-get-site-info', 'core-get-user-info', 'core-nope']) {
    check(`tools/list exposes ${t}`, toolNames.includes(t), toolNames.join(','));
  }

  // ---------- D29 assertion 1: governed allowed call ----------
  const beforeAllowed = parseInt(sql(`SELECT COUNT(*) FROM wp_agsafe_audit_log WHERE ability='core/get-site-info' AND decision='allowed'`), 10);
  const siteInfo = await mcp(ctx, AUTH, {
    jsonrpc: '2.0', id: 3, method: 'tools/call',
    params: { name: 'core-get-site-info', arguments: {} },
  }, sessionId);
  check('core-get-site-info HTTP 200', siteInfo.status === 200, siteInfo.status);
  const siteCallOk = siteInfo.json?.result && !siteInfo.json.result.isError;
  check('core-get-site-info executes without error', siteCallOk, JSON.stringify(siteInfo.json).slice(0, 400));
  const afterAllowed = parseInt(sql(`SELECT COUNT(*) FROM wp_agsafe_audit_log WHERE ability='core/get-site-info' AND decision='allowed'`), 10);
  check('audit row(s) allowed for core/get-site-info', afterAllowed > beforeAllowed, `before=${beforeAllowed} after=${afterAllowed}`);

  // ---------- D29 assertion 2: unknown verb in a governed namespace ----------
  const nopeBefore = sql(`SELECT COUNT(*) FROM wp_agsafe_audit_log WHERE ability='core/nope'`);
  const nopeExecBefore = evalPhp(`echo (int) get_option("agsafe_smoke_exec_nope", 0);`);
  const nope = await mcp(ctx, AUTH, {
    jsonrpc: '2.0', id: 4, method: 'tools/call',
    params: { name: 'core-nope', arguments: {} },
  }, sessionId);
  check('core-nope denied (isError result)', nope.json?.result?.isError === true, JSON.stringify(nope.json).slice(0, 400));
  const nopeAfter = sql(`SELECT COUNT(*) FROM wp_agsafe_audit_log WHERE ability='core/nope'`);
  check('audit row written for core/nope attempt', parseInt(nopeAfter, 10) > parseInt(nopeBefore, 10), `before=${nopeBefore} after=${nopeAfter}`);
  const unknownReason = sql(`SELECT COUNT(*) FROM wp_agsafe_audit_log WHERE ability='core/nope' AND decision='denied' AND record_json LIKE '%unknown_verb%'`);
  check('denial reason is unknown_verb', parseInt(unknownReason, 10) >= 1, `rows=${unknownReason}`);
  check('core-nope never executed', evalPhp(`echo (int) get_option("agsafe_smoke_exec_nope", 0);`) === nopeExecBefore);

  // ---------- D29 assertion 3: user PII masked on the read path ----------
  const userInfo = await mcp(ctx, AUTH, {
    jsonrpc: '2.0', id: 5, method: 'tools/call',
    params: { name: 'core-get-user-info', arguments: {} },
  }, sessionId);
  const userText = resultText(userInfo.json?.result);
  check('core-get-user-info executes without error', userInfo.json?.result && !userInfo.json.result.isError, JSON.stringify(userInfo.json).slice(0, 400));

  // The actual admin email must not leak anywhere in the response payload.
  check('no raw user_email value in response', !userText.includes(ADMIN_EMAIL), userText.slice(0, 400));
  // D29: the response carries NO user_email key at all. And where the
  // payload does have PII-shaped keys, read-path redaction masks them IN
  // PLACE («redacted») — the generic fragment rule catches first/last name.
  check('response carries no user_email key', !/"user_email"/.test(userText), userText.slice(0, 400));
  const piiValues = [...userText.matchAll(/"(first_name|last_name|nickname|display_name)"\s*:\s*"([^"]*)"/g)].map(m => m[2]);
  check('PII-shaped keys never carry raw identifying values', piiValues.every(v => v === '' || /redacted/i.test(v)), JSON.stringify(piiValues));
  check('no user_pass material in response', !/"user_pass"\s*:\s*(?!"«redacted»")/.test(userText), userText.slice(0, 200));
  // user_login/user_url stay readable so approvals can name their target.
  check('user_login kept visible', /"user_login"\s*:\s*"(?!«redacted»)/.test(userText), userText.slice(0, 400));

  // ---------- D29 assertion 4: audit chain intact ----------
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  await page.goto(BASE + '/wp-login.php');
  await page.context().request.post(BASE + '/wp-login.php', {
    form: { log: 'admin', pwd: 'password', 'wp-submit': 'Log In', redirect_to: BASE + '/wp-admin/', testcookie: '1' },
  });
  await page.goto(BASE + '/wp-admin/tools.php?page=agent-safety-audit');
  const auditText = await page.textContent('body');
  check('audit page shows Chain intact', auditText.includes('Chain intact'), auditText.slice(0, 500));
  check('audit page lists a core/get-site-info event', auditText.includes('core/get-site-info'), '');
  check('audit page lists the unknown_verb denial', auditText.includes('unknown_verb'), '');
  await page.screenshot({ path: SHOTS + '/08-core-module-audit.png', timeout: 8000 });

  // Packs screen shows the core presets (bootstrap merge, Woo off).
  await page.goto(BASE + '/wp-admin/tools.php?page=agent-safety-packs');
  const packsText = await page.textContent('body');
  for (const p of ['site-readonly', 'content-editor', 'site-admin-agent']) {
    check(`packs page lists ${p} (Woo off)`, packsText.includes(p), '');
  }
  await page.screenshot({ path: SHOTS + '/09-core-presets-woo-off.png', timeout: 8000 });

  await browser.close();
  await ctx.dispose();

  const failed = results.filter(r => !r.pass);
  console.log(`\n=== ${results.length - failed.length}/${results.length} checks passed ===`);
  if (failed.length) process.exit(1);
})().catch(e => { console.error('SMOKE CRASH:', e); process.exit(2); });
