---
status: accepted
date: 2026-08-25
---

# One Verdict pipeline; the mcp pre-tool-call hook is a peek-mode adapter of it

`AbilityPermissionGate` (the Abilities API `permission_callback`, live on every stack) and
`PreToolCallGate` (`mcp_adapter_pre_tool_call`, which mcp-adapter only fires from v0.5.0 —
dormant on Woo 11, whose composer pin is `^0.3.0`) each carried their own copy of the ordered
evaluation, and they had drifted: only the mcp copy elevated a Decision on a destructive Hint,
so the same call could be tiered differently depending on which seam saw it. We decided that a
single host-side Verdict pipeline (`plugin/src/`) owns the whole sequence — resolve Pack, run the
pure core `Gate`, consult the Approval, apply Hints, apply rate and argument caps, honour Shadow
mode, persist the pending Approval and audit a call that will not execute — and that both hooks
are adapters of it differing only in mode: the mcp hook *peeks* at the Approval and may
short-circuit early (so the agent receives `approval_required` with its `approval_id` before
`WP_Ability::execute()` masks it to `ability_invalid_permissions`), while the Abilities hook
*claims* the grant and stays the sole owner of reserve → finalize → rollback. Hints are a plain
pipeline input on both seams; the Abilities adapter reads them from ability meta (`readonly`,
`destructive`), which the Abilities API already carries.

## Considered options

- **Delete `PreToolCallGate`** and read Hints on the Abilities seam only. Rejected: on adapters
  ≥ 0.5.0 the early short-circuit is the only way a consumer such as SenroFlux sees the real
  approval error data, because `WP_Ability::execute()` discards a permission callback's `WP_Error`.
- **Keep two evaluators and deduplicate helpers only.** Rejected: the drift was in the sequence,
  not the helpers; sharing helpers leaves the ordering hand-maintained twice.

## Consequences

- The `WP_Error` contract (`agent_safety_denied`, `approval_required`; data keys `status`,
  `verb`, `tier`, `approval_id`, nulls dropped) has exactly one producer, pinned by a fixture
  test under `plugin/tests/Fixtures/` that consumers (SenroFlux) run against the same fixture.
- The peek-mode adapter is maintained even while dormant on the shipping Woo stack; do not
  remove it as dead code.
- Governed-namespace scoping and the Approval lifecycle beyond *claim* are deliberately outside
  the pipeline (separate deepenings).
