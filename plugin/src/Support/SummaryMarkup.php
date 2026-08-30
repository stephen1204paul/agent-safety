<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Provenance tag for approval summaries (AS-11 hardening).
 *
 * An approval summary is built from RAW AGENT INPUT: {@see DecisionRecorder::summarize()}
 * interpolates argument values an attacker fully controls. Rendering that through
 * `wp_kses()` — even a one-element allow-list — would let an agent plant a live
 * `<a href="...">` in the wp-admin approval queue, i.e. stored XSS-adjacent bait
 * aimed at the exact administrator who is about to approve an irreversible action.
 *
 * So the two provenances must stay distinguishable AFTER the row round-trips
 * through the database, where the renderer no longer has the pre-filter value to
 * compare against. `wrap()` tags a summary that the `agent_safety_approval_summary`
 * filter ACTUALLY CHANGED — a deliberate site-integration enrichment — and only a
 * tagged summary is later rendered as markup. Everything else is escaped.
 *
 * The tag is unreachable from agent input: `summarize()` always opens with the
 * ability name (`orders/refund { ... }`), never with a control character, and the
 * tag is only ever attached by the recorder itself after the comparison. An
 * untagged or unrecognised value therefore fails closed to plain text.
 */
final class SummaryMarkup
{
    /**
     * Non-printable sentinel, deliberately not expressible through the flat
     * summary grammar. Changing it only affects rows written after the change:
     * older rows simply read back as plain text, which is the safe direction.
     */
    private const TAG = "\x02agent-safety:html\x03";

    /** Tag host-authored markup so the approval screen may render it as markup. */
    public static function wrap(string $html): string
    {
        return self::TAG . $html;
    }

    /** True when this stored summary was authored by a site integration, not by the agent. */
    public static function isHostAuthored(string $stored): bool
    {
        return str_starts_with($stored, self::TAG);
    }

    /**
     * The summary as authored, with the provenance tag removed. Callers that
     * emit into HTML must still escape (agent-authored) or sanitise
     * (host-authored) the result — see {@see \Specflux\AgentSafety\Plugin\Admin\PendingActionsPage::summaryHtml()}.
     */
    public static function unwrap(string $stored): string
    {
        return self::isHostAuthored($stored) ? substr($stored, strlen(self::TAG)) : $stored;
    }
}
