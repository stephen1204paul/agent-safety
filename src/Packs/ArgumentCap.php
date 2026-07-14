<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Packs;

/**
 * One argument-aware cap in a Pack's policy envelope (roadmap 0.2 "spend
 * limits"): a constraint that reads a value out of the CALL'S ARGUMENTS
 * rather than merely counting calls. Declared per pack, scoped to a verb
 * glob, and evaluated by the pure {@see ArgumentCapPolicy}.
 *
 * Four constraints, each independent and nullable (null = not enforced):
 *
 *   - $approvalAbove:   a per-call value above this requires human approval
 *                       (the existing approval flow — pending row, wp-admin
 *                       review, single-use grant), e.g. "order edits above
 *                       500 always need a human".
 *   - $maxPerCall:      a per-call hard ceiling; above it the call is denied
 *                       outright.
 *   - $maxTotalPerDay:  a fixed-window (UTC calendar day) cap on the SUM of
 *                       this value across admitted calls per (pack, identity),
 *                       e.g. "at most 1000 in refunds per day". The host
 *                       accumulates only calls that were actually admitted —
 *                       a denial never consumes budget, mirroring D26.
 *   - $maxItemsPerCall: a ceiling on count($value) when the argument is a
 *                       list, e.g. "bulk updates touch at most 25 items".
 *
 * Values are compared by MAGNITUDE (absolute value): a refund of -500 moves
 * as much money as +500, and signed values would let an agent drive the daily
 * total DOWN and reopen a spent budget. Value constraints require a numeric
 * argument (numeric strings included — WooCommerce amounts arrive as
 * strings); $maxItemsPerCall requires an array. A missing or malformed
 * argument DENIES the call (fail closed): if a cap governs a value the call
 * refuses to present legibly, the call does not run.
 */
final class ArgumentCap
{
    /**
     * @param string $id      Names this cap in denial reasons, the audit
     *                        trail, and the host's accumulator bucket. Snake
     *                        case by convention, e.g. "refund_total".
     * @param string $verbs   Verb glob this cap applies to, same dialect as
     *                        {@see Pack::$allow} (e.g. "woocommerce/orders-*").
     * @param string $argPath Dot-notation path into the call args to the
     *                        governed value, e.g. "amount" or "refund.total".
     */
    public function __construct(
        public readonly string $id,
        public readonly string $verbs,
        public readonly string $argPath,
        public readonly ?float $approvalAbove = null,
        public readonly ?float $maxPerCall = null,
        public readonly ?float $maxTotalPerDay = null,
        public readonly ?int $maxItemsPerCall = null,
    ) {
    }

    public function appliesTo(string $verb): bool
    {
        return VerbGlob::matches($this->verbs, $verb);
    }

    /** True when this cap sums admitted values across a day window (the host must track totals for it). */
    public function accumulates(): bool
    {
        return $this->maxTotalPerDay !== null;
    }

    /** True when this cap reads the argument as a numeric value (vs. only counting list items). */
    public function readsValue(): bool
    {
        return $this->approvalAbove !== null
            || $this->maxPerCall !== null
            || $this->maxTotalPerDay !== null;
    }
}
