<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Verdict;

use Specflux\AgentSafety\Approval\ApprovalBinding;
use Specflux\AgentSafety\Approval\ApprovalStore;
use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Gate\GateContext;
use Specflux\AgentSafety\Gate\Outcome;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Support\ArgumentCapGate;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\RateLimitGate;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Support\ShadowMode;
use Specflux\AgentSafety\Policy\Tier;

/**
 * The ordered, fail-closed evaluation that turns one governed call into a
 * {@see Verdict}. Both gate seams — the Abilities API `permission_callback`
 * ({@see \Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate}) and
 * mcp-adapter's `mcp_adapter_pre_tool_call`
 * ({@see \Specflux\AgentSafety\Plugin\Hooks\PreToolCallGate}) — are adapters
 * of this one module, differing only in {@see VerdictMode}, so the same call
 * can never be judged two different ways (docs/adr/0001-single-verdict-pipeline.md).
 *
 * The ORDER is the product:
 *   1. Core {@see Gate} evaluates verb + args + pack with the approval state the
 *      mode allows (peek: a non-mutating lookup; claim: none yet).
 *   2. A self-reported destructive hint may only TIGHTEN the decision.
 *   3. Claim mode only: if approval is now the SOLE blocker, reserve a human
 *      grant for this exact action and re-evaluate as approved. Reservation is
 *      a side effect, so it must never fire while any other deny gate
 *      (unknown verb, not-in-pack, class-denied) would still block the call.
 *   4. Rate/quota caps, then argument-aware caps, bind only on a call that
 *      would otherwise proceed — a denial must never itself consume budget.
 *   5. Shadow mode: a pack in log-only observation audits the would-be verdict
 *      as a dry run and lets the call proceed; no pending approval is minted for
 *      an action that already ran.
 *   6. A call that will NOT execute persists its pending approval (when
 *      required) and is audited HERE, because the execution seam never runs
 *      for it. Allowed calls are audited at execution time by AbilityAuditLog.
 *
 * One instance is shared by both seams within a request: the re-entrancy memo
 * below is what lets WordPress re-enter a permission callback ~11 times per
 * REST request (route matching, Allow-header probing, execute-time re-check)
 * without reserving the same grant twice.
 */
final class VerdictPipeline
{
    /** @var array<string, true> verb|args_hash already claimed in THIS request (re-entrancy guard). */
    private array $reentry = [];

    public function __construct(
        private readonly Gate $gate,
        private readonly DecisionRecorder $recorder,
        private readonly ?ApprovalStore $approvals = null,
        private readonly RateLimitGate $rateLimits = new RateLimitGate(),
        private readonly ArgumentCapGate $argumentCaps = new ArgumentCapGate(),
        private readonly ShadowMode $shadow = new ShadowMode(),
    ) {
    }

    /** @param array<string, mixed> $args */
    public function judge(string $verb, array $args, Pack $pack, Hints $hints, VerdictMode $mode): Verdict
    {
        $peeked = VerdictMode::Peek === $mode && $this->recorder->hasApprovedGrant($verb, $args);
        $decision = $this->evaluate($verb, $args, $pack, $hints, $peeked);

        $claimed = false;
        $reservedId = null;
        if (VerdictMode::Claim === $mode && Outcome::ApprovalRequired === $decision->outcome) {
            $claimed = $this->claim($verb, $args, $reservedId);
            if ($claimed) {
                $decision = $this->evaluate($verb, $args, $pack, $hints, true);
            }
        }
        $hasValidApproval = $peeked || $claimed;

        if (Outcome::Allow === $decision->outcome) {
            $decision = $this->enforceRateLimit($pack, $decision, $verb, $args);
        }

        if (Outcome::Allow === $decision->outcome) {
            $decision = $this->enforceArgumentCaps($pack, $decision, $verb, $args, $hasValidApproval, $mode, $claimed, $reservedId);
        }

        if (Outcome::Allow === $decision->outcome) {
            return new Verdict($verb, $pack, $decision, null, $reservedId, $claimed);
        }

        $eventId = RequestContext::event();

        if ($this->shadow->isShadow($pack->name)) {
            $this->recorder->auditDecision($eventId, $verb, $args, $pack, $decision, null, true);

            return new Verdict($verb, $pack, $decision, null, $reservedId, $claimed, true, $eventId);
        }

        $approvalId = Outcome::ApprovalRequired === $decision->outcome
            ? $this->recorder->requestApproval($verb, $args, $eventId)
            : null;
        $this->recorder->auditDecision($eventId, $verb, $args, $pack, $decision, $approvalId);

        return new Verdict($verb, $pack, $decision, $approvalId, $reservedId, $claimed, false, $eventId);
    }

    /**
     * Steps 1 and 2: the core decision, then the destructive-hint tighten.
     *
     * @param array<string, mixed> $args
     */
    private function evaluate(string $verb, array $args, Pack $pack, Hints $hints, bool $hasValidApproval): Decision
    {
        $decision = $this->gate->evaluate(new GateContext(
            verb: $verb,
            args: $args,
            pack: $pack,
            selfReportedReadonly: $hints->readonly,
            hasValidApproval: $hasValidApproval,
        ));

        return $this->elevateForDestructiveHint($decision, $pack, $hints->destructive, $hasValidApproval);
    }

    /**
     * A destructiveHint === true on a call our own classifier placed below
     * {@see Tier::Irreversible} is treated as though it HAD classified there:
     * the call now faces whatever the pack demands of an irreversible verb (a
     * hard deny-class wall, or approval). Only ever touches a decision that is
     * currently Allow — a call already Denied or ApprovalRequired for another
     * reason is at least as strict — so this can turn Allow into
     * Deny/ApprovalRequired but never the reverse. That is what makes the
     * "a hint never relaxes" rule hold by construction.
     *
     * Lives here rather than in the core Gate because the hint comes from the
     * adapter (mcp tool annotations, ability meta), which the framework-agnostic
     * core never sees.
     */
    private function elevateForDestructiveHint(
        Decision $decision,
        Pack $pack,
        bool $selfReportedDestructive,
        bool $hasValidApproval,
    ): Decision {
        if (!$selfReportedDestructive || Outcome::Allow !== $decision->outcome || Tier::Irreversible === $decision->tier) {
            return $decision;
        }

        if ($pack->deniesClass(Tier::Irreversible)) {
            return Decision::deny('denied_by_class_destructive_hint', Tier::Irreversible);
        }

        if ($pack->requiresApproval(Tier::Irreversible) && !$hasValidApproval) {
            return Decision::approvalRequired(Tier::Irreversible);
        }

        return Decision::allow(Tier::Irreversible);
    }

    /**
     * Enforce this pack's rate/quota caps on a decision that is otherwise
     * Allow. Returns the SAME decision when admitted (the call has just been
     * counted against the pack's limits as a side effect), or a Deny naming
     * the tripped limit.
     *
     * @param array<string, mixed> $args
     */
    private function enforceRateLimit(Pack $pack, Decision $decision, string $verb, array $args): Decision
    {
        $tripped = $this->rateLimits->admit($pack, RequestContext::tokenId(), $verb, $args);

        return $tripped === null ? $decision : Decision::deny('rate_limited_' . $tripped, $decision->tier);
    }

    /**
     * Enforce this pack's argument-aware caps ("spend limits") on a decision
     * that is otherwise Allow. Hard trips deny, naming the cap and constraint
     * (e.g. "argument_cap_refund_total_max_total_per_day") like rate-limit
     * denials name their window.
     *
     * A tripped approval threshold routes through the SAME human-approval
     * machinery the tier gate uses. In claim mode, if a grant for this exact
     * verb+args exists it is claimed now (otherwise the approved retry would
     * trip the threshold again forever — the tier path never claims for a verb
     * whose tier needs no approval) and the caps are re-checked, because the
     * day totals are re-read live: a concurrent request may have spent the
     * remaining budget since the first check, and a human grant satisfies only
     * the threshold, never a hard cap. In peek mode the call parks as
     * approval-required; an already-approved retry sails through because
     * $hasValidApproval (the non-mutating peek) satisfies the threshold.
     *
     * @param array<string, mixed> $args
     */
    private function enforceArgumentCaps(
        Pack $pack,
        Decision $decision,
        string $verb,
        array $args,
        bool $hasValidApproval,
        VerdictMode $mode,
        bool &$claimed,
        ?string &$reservedId,
    ): Decision {
        $check = $this->argumentCaps->check($pack, RequestContext::tokenId(), $verb, $args, $hasValidApproval);
        if ($check->allowed) {
            return $decision;
        }

        if ($check->requiresApproval) {
            if (VerdictMode::Claim === $mode && $this->claim($verb, $args, $reservedId)) {
                $claimed = true;
                $recheck = $this->argumentCaps->check($pack, RequestContext::tokenId(), $verb, $args, true);

                return $recheck->allowed
                    ? $decision
                    : Decision::deny('argument_cap_' . $recheck->trippedCap . '_' . $recheck->constraint, $decision->tier);
            }

            // An Allow decision always carries its tier; the fallback exists
            // only to fail closed (treat as irreversible) if that ever changes.
            return Decision::approvalRequired($decision->tier ?? Tier::Irreversible);
        }

        return Decision::deny('argument_cap_' . $check->trippedCap . '_' . $check->constraint, $decision->tier);
    }

    /**
     * Atomically claim a human grant for this exact verb+args — by bearer
     * token if the agent threaded one back, else by-reference for the
     * requesting key. $reservedId receives the approval id reserved by THIS
     * call, or stays null on a re-entrant hit: Woo runs an allowed ability
     * through rest_do_request, which RE-ENTERS the permission check within the
     * SAME request while the grant is already `in_flight`, so the first claim
     * per (verb, args_hash) is memoized and later checks pass without a second
     * reservation. Cross-request single-claim is still enforced by the store.
     *
     * @param array<string, mixed> $args
     */
    private function claim(string $verb, array $args, ?string &$reservedId): bool
    {
        if ($this->approvals === null) {
            return false;
        }

        $argsHash = ApprovalBinding::hash($verb, $args);
        $memoKey = $verb . '|' . $argsHash;
        if (isset($this->reentry[$memoKey])) {
            return true;
        }

        $token = isset($args[ApprovalBinding::TOKEN_ARG]) ? (string) $args[ApprovalBinding::TOKEN_ARG] : null;
        $approvalId = $this->approvals->reserve($token, $verb, $argsHash, RequestContext::tokenId());
        if ($approvalId === null) {
            return false;
        }

        $this->reentry[$memoKey] = true;
        $reservedId = $approvalId;

        return true;
    }
}
