<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Approval;

use Specflux\AgentSafety\Plugin\Support\SummaryMarkup;

/**
 * Best-effort extraction of the `id` argument back out of an Approval's
 * persisted `summary` text, for the approve-time state re-probe (AS-6 §3.3
 * item 8) — the approvals table stores only `args_hash` (a one-way hash),
 * never the raw arguments, and schema v4 is frozen for this release, so
 * this is the only surviving copy of the target id once a request is on
 * the Pending Actions screen.
 *
 * Deliberately narrow and fail-OPEN: {@see \Specflux\AgentSafety\Plugin\Support\DecisionRecorder::summarize()}
 * emits `key=value` pairs in argument order (`id=42, regular_price=19.99`),
 * but a site's `agent_safety_approval_summary` filter may have replaced that
 * text with something host-authored ({@see SummaryMarkup::isHostAuthored()})
 * that does not contain it at all. When extraction fails for either reason,
 * the caller treats the re-probe as INCONCLUSIVE and lets approve() proceed
 * exactly as it did before AS-6 — failing closed here would block every
 * approval on any site that customises its summaries, which is worse than
 * an occasionally-skipped staleness check. A future release that wants this
 * fully robust needs a schema column carrying the raw args, out of scope
 * for this stage.
 */
final class SummaryArgs
{
    public static function extractId(string $summary): ?int
    {
        if (SummaryMarkup::isHostAuthored($summary)) {
            return null;
        }

        if (preg_match('/(?:^|[{,]\s*)id=(\d+)/', $summary, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
