<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Gate;

use Specflux\AgentSafety\Policy\TierClassifier;

/**
 * The decision core. Pure function of (verb, args, pack):
 * no WordPress, no I/O, no side effects — so it is exhaustively unit-testable.
 *
 * Evaluation order is deliberately fail-closed:
 *   1. unknown verb            -> deny (never trust an unclassified verb)
 *   2. readonly-but-writes     -> deny (annotation lied)
 *   3. not in pack allow-list  -> deny
 *   4. tier class hard-denied  -> deny (the injection-proof wall)
 *   5. approval required + none -> approval_required
 *   6. otherwise               -> allow
 */
final class Gate
{
    public function __construct(
        private readonly TierClassifier $classifier = new TierClassifier(),
    ) {
    }

    public function evaluate(GateContext $ctx): Decision
    {
        $tier = $this->classifier->classify($ctx->verb, $ctx->args);

        if ($tier === null) {
            return Decision::deny('unknown_verb');
        }

        if ($this->classifier->isReadonlyButWrites($ctx->verb, $ctx->selfReportedReadonly, $ctx->args)) {
            return Decision::deny('readonly_but_writes', $tier);
        }

        if (!$ctx->pack->allows($ctx->verb)) {
            return Decision::deny('not_in_pack', $tier);
        }

        if ($ctx->pack->deniesClass($tier)) {
            return Decision::deny('denied_by_class', $tier);
        }

        if ($ctx->pack->requiresApproval($tier) && !$ctx->hasValidApproval) {
            return Decision::approvalRequired($tier);
        }

        return Decision::allow($tier);
    }
}
