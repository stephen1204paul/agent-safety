# Agent Safety

The governed safety and audit layer for AI-agent tool calls in WordPress: every call an agent makes is judged against a Pack, and what happened to it is recorded.

## Language

### Policy

**Pack**:
A named capability profile bound to an agent identity: which Verbs it may call, which Tiers need approval, its caps, and whether it is in Shadow mode.
_Avoid_: profile, role, policy set

**Verb**:
The canonical name of an action an agent can take (an ability or tool id, after mapping).
_Avoid_: tool, ability, action

**Tier**:
The risk class of a Verb — read, write, or irreversible — that a Pack's rules key on.
_Avoid_: level, class, severity

**Hint**:
A self-reported readonly or destructive annotation carried by the tool or ability. A Hint may only tighten a Decision, never loosen it.
_Avoid_: annotation, flag

**Governed namespace**:
An ability namespace that Agent Safety judges; calls outside it pass through untouched.
_Avoid_: scope, allowlist

### Judging a call

**Gate**:
The pure rule engine that turns a Verb, its arguments, a Pack and the approval state into a Decision. It performs no I/O.
_Avoid_: policy engine, evaluator

**Decision**:
What the rules say about a call: allow, deny, or approval required, with the Tier and a reason.
_Avoid_: result, outcome (outcome is one field of a Decision)

**Verdict pipeline**:
The ordered host-side evaluation that turns one call into a Verdict: resolve the Pack, run the Gate, consult the Approval, apply Hints, apply caps, honour Shadow mode, and record the obligations of a call that will not execute.
_Avoid_: gate hook, permission callback, handler

**Verdict**:
What actually happened to a call after the Verdict pipeline ran: the Decision plus its consequences — the Approval id, whether a grant was claimed, whether it was shadowed, and the audit event it was recorded under.
_Avoid_: response, error

**Peek**:
Consulting an Approval without claiming it. The first stage of a two-stage call does this so a grant is never claimed twice.
_Avoid_: check, lookup

**Claim**:
Reserving an Approval for the call about to execute; spent on success, released if the call never runs.
_Avoid_: consume, use, take

**Shadow mode**:
A Pack setting under which a Decision that would block a call is audited as a dry run and the call proceeds anyway.
_Avoid_: observe-only, log-only, dry run (dry run is the audit marker, not the mode)

### Approval and audit

**Approval**:
A human grant for one exact action (Verb plus canonical arguments), requested when a Decision is approval required and resolved on the Pending Actions page or programmatically.
_Avoid_: grant (the granted state of an Approval), request, ticket

**Audit record**:
One hash-chained row describing a call and its Verdict, written once per call whether or not it executed.
_Avoid_: log entry, event (event id is the correlation key, not the record)
