# Release 0.4.0 end-to-end proof

A live wp-env harness proving spec rows **P1-P8** against the actual built
0.4.0 plugin ZIP (not the source checkout mounted as a directory, the way the
other `dev/smoke/*` harnesses run — this one installs the real artifact a
site administrator would download).

## Prerequisites

- Docker running, with headroom on the Docker VM disk (the harness checks
  this itself via `check_disk_or_die` and refuses to start above 90% used).
- Node 22+.
- `plugin/vendor` does **not** need to be pre-installed — the harness runs
  `bin/build-zip.sh` itself, which does its own `composer install --no-dev`
  (and, with `--restore-dev-deps`, restores the dev deps afterwards so a
  local checkout is still usable for `vendor/bin/phpunit` once the harness
  finishes).
- Nothing else running on ports **8970** (site) / **8971** (wp-env's tests
  instance, unused here but reserved by wp-env's config shape).

## How to run

```sh
./dev/smoke/release-0.4.sh
```

Everything else — building both zips, exporting the pinned mcp-adapter,
starting wp-env, provisioning, and running every row — happens inside that
one script. It prints a `PASS`/`FAIL` line per check as it goes, then a final
consolidated `P1`..`P8` table, and exits non-zero if any row failed.

When done, `npx @wordpress/env stop` runs automatically (never `destroy` —
`stop` leaves the environment for a re-run). Set `AGSAFE_KEEP_ENV=1` to skip
that and leave the environment running for manual poking afterwards.

## What each row proves

- **P1 Install** — the 0.4.0 zip installs and activates on a single site
  (`P1a`); network-activating it on a multisite conversion of the SAME
  install is refused with the exact string from
  `plugin/src/Support/MultisiteGuard.php::message()`: *"Agent Safety does
  not support WordPress multisite. It was not activated."* (`P1b`, run
  **last** — multisite conversion is one-way for this wp-env instance, and
  P8 has already uninstalled the plugin data by the time P1b reinstalls the
  zip without activating).
- **P2 Upgrade** — starts from a 0.3-branch install holding a grant, a
  pending approval, an approved-but-unclaimed approval, and a shadow window;
  upgrading to 0.4.0 (files replaced, DB rows untouched) leaves schema
  version `4`, all rows intact, one `environment.bound` audit event (first
  bind on the pre-existing install), and `AuditReader::verifyChain()` true
  (checked via the audit page's "Chain intact." text over HTTP, simpler than
  wiring a bespoke `wp eval-file` reader).
- **P3 Stale** — a Woo MCP `products-delete{force:true}` approval, once
  approved, goes stale if the product changes before the agent retries: the
  retry gets a **new** approval id and the exact §3.11 stale message; the old
  row flips to `status='stale'`; the product survives.
- **P4 Site moved** — changing `home`/`siteurl` permanently voids every
  Relaxation (shadow windows, approved-but-unclaimed approvals): one
  `environment.mismatch` audit row, the approval becomes `void_environment`,
  the shadow option empties, an admin notice appears, and rebinding clears
  only the mismatch lock — it restores nothing.
- **P5 Check-approval** — polling `agent-safety/check-approval` on the
  **default** mcp-adapter server (not Woo's own endpoint) walks an approval
  through `pending` → `approved` → `used`; a second principal's poll is
  byte-identical to an unknown id's response (no existence oracle); an 11th
  poll inside one minute is `rate_limited`.
- **P6 Production shadow** — with `WP_ENVIRONMENT_TYPE` unset (defaults to
  `production`), enabling a shadow window through the real admin HTML form
  requires typing the pack name to confirm; without it, nothing is shadowed;
  with it, the stored expiry is capped at 24h.
- **P7 Privacy** — WordPress's own personal-data export/erase APIs, run
  against the Shop Manager's audit rows: the exporter returns only that
  user's rows, the eraser reports them `retained` with the exact
  "tamper-evident" wording from `plugin/src/Privacy/PersonalDataEraser.php`,
  and the audit page still shows "Chain intact." afterwards (erasure never
  breaks the hash chain — the whole point of retaining, not erasing).
- **P8 Uninstall** — `AGSAFE_REMOVE_DATA=true` + `wp plugin uninstall` leaves
  no `agsafe_%` options, no `{prefix}agsafe_%` tables, no Agent Safety cron
  events, and no leftover `_transient_agsafe_%` rows. Run near the end
  (inside `run.js`, before P1b) because it deletes all Agent Safety data —
  P1b's later reinstall starts from a clean slate on purpose.

## Design choices worth knowing about

- **Two zips.** `bin/build-zip.sh --restore-dev-deps` builds the real 0.4.0
  artifact from this worktree. A second zip is hand-built from
  `release/senroflux-0.3` via `git archive` (root of that ref, so both
  `plugin/` and the core library `src/` come from the same commit — the
  plugin's composer path repo resolves relative to that shared root) +
  `composer install --no-dev`, giving the upgrade leg (P2) a real prior
  release to start from.
- **mcp-adapter pinned at `07c9912`**, exported read-only via `git archive`
  against `research/mcp-adapter` and installed into wp-env as a local plugin
  path. `research/mcp-adapter` deliberately carries uncommitted local work
  (per this repo's `CLAUDE.md`) — `git archive` only reads a tree, it never
  touches the working directory or index, so this is safe. No other git
  command is ever run against that checkout from this harness.
- **Multisite conversion runs last** (P1b) because `wp core multisite-convert`
  is one-way for a wp-env instance — nothing after it can go back to a
  single-site test.
- **Uninstall (P8) runs near the end**, inside `run.js`, because it deletes
  every row and option the plugin owns; P1b's later reinstall deliberately
  starts from that clean slate.
- **`npx @wordpress/env stop`**, never `destroy`, runs automatically via a
  bash `trap` on `EXIT` — set `AGSAFE_KEEP_ENV=1` to skip it.

## Known deviations from a straight reading of the original brief

- **`research/mcp-adapter` is not inside this git worktree.** This worktree
  (`agent-safety-wt-s15-proof`) is itself the `agent-safety` repo; the
  `research/` tree lives one level up, as a sibling checkout in the parent
  planning directory. `release-0.4.sh` resolves it as
  `"$REPO_ROOT/../research/mcp-adapter"` rather than
  `"$REPO_ROOT/research/mcp-adapter"`.
- The rebind nonce action (`agsafe_rebind_environment`) carries **no id
  suffix** — confirmed by reading `plugin/src/Admin/CapabilityPacksPage.php`;
  only the per-approval approve/reject nonces (`agsafe_approve_action<id>`)
  do.
- **Correction (post-review):** the environment-bound/mismatch audit rows'
  `ability` column is actually the OPTION name (`agsafe_site_binding`), not
  the event name — `AdminChangeRecorder::append(string $event, string
  $option, array $input)` stores `$option` as `ability` and `$event` only
  inside `record_json`'s `"reason"` key (confirmed against
  `AdminChangeRecorder.php`, `WpdbAuditSink.php` and
  `AuditRecord::toArray()`/`canonicalJson()` — the audit table has no
  standalone `reason` column, only `ability`, `decision`, `tier`, `result`,
  `wp_user`, `ip`, `record_json`). `run.js` was corrected to query
  `ability='agsafe_site_binding' AND record_json LIKE
  '%"reason":"environment.bound"%'` (and `...environment.mismatch...` for
  P4) rather than the original (wrong) `ability='environment.bound'`.
