# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

**Agent Safety** — a WordPress plugin adding a governed safety/audit layer for AI agent tool
calls (Abilities API / MCP): capability packs, a deny-by-default gate, human approvals, and a
hash-chained audit log. WooCommerce is supported via a capability-pack integration module;
the core is WordPress-general.

**The design principle that cannot change:** every addition must fail closed, and nothing a
tool or agent self-reports may ever loosen a decision. Packs narrow what a credential may do,
never widen it — underlying WordPress capability checks still apply.

## Layout (two composer packages)

- **Root** — framework-agnostic core library (`specflux/agent-safety-core`, PHP >=8.1).
  **No WordPress function calls allowed anywhere in `src/`** (Approval, Audit, Gate, Packs,
  Policy). Tests: PHPUnit ^10.5 in `tests/`.
- **`plugin/`** — the WordPress plugin host (`specflux/agent-safety`, main file
  `plugin/agent-safety.php`), depending on the core via a composer **path repo that COPIES,
  not symlinks** — after any change under root `src/`, run
  `cd plugin && composer reinstall specflux/agent-safety-core` (or `composer install`).
- All WooCommerce coupling lives in `plugin/src/Integrations/Woo/`. Identity is a provider
  chain in `plugin/src/Identity/` (application passwords, user/role, WC keys via the Woo
  module). The gate governs only namespaces contributed by integrations or the
  `agent_safety_governed_namespaces` filter.

## Commands

```sh
vendor/bin/phpunit                            # core suite (phpunit.xml.dist, failOnWarning/failOnRisky)
vendor/bin/phpunit -c plugin/phpunit.xml.dist # plugin suite (WP shims + adapter stub in plugin/tests/bootstrap.php)
vendor/bin/phpunit --filter GateTest          # single test class
```

Quality gates before any push: both suites green, PHPCS clean, PHPStan level 8 clean.

## Testing conventions

- The plugin suite runs against WP shims + an mcp-adapter stub — no live WordPress needed.
- Bugs that unit tests keep missing live on the real request path (identity timing, DB-backed
  transient types, re-entrant permission callbacks) — when touching those seams, say so and
  flag that a wp-env smoke is the real check (harness seed lives in the parent planning
  directory under `dev/smoke/`).

## Git conventions

- Sole authorship: **never** add `Co-Authored-By` or any AI-attribution trailers to commits.
- `main` is the only branch; keep it releasable (all gates green).

## Where the plan lives

`ROADMAP.md` in this repo is the public ordering of upcoming work and the non-goals. Read it
before proposing features — several "obvious" additions (agent-side safety, replacing WP
capabilities) are explicit non-goals.
