<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Verdict;

/**
 * How the {@see VerdictPipeline} treats a human grant for the call it is judging.
 *
 * One tool call can pass through TWO gate seams in sequence — mcp-adapter's
 * `mcp_adapter_pre_tool_call` filter (adapter >= 0.5.0) and then the ability's
 * own `permission_callback` (every stack). Both must reach the same verdict,
 * but a grant may be reserved only once, so the first seam PEEKS and the
 * second CLAIMS. See docs/adr/0001-single-verdict-pipeline.md.
 */
enum VerdictMode
{
    /**
     * Consult the approval store without mutating it: an already-approved
     * retry is allowed to PROCEED so the claiming seam can reserve the grant.
     */
    case Peek;

    /**
     * Atomically reserve the grant (approved -> in_flight) when approval is the
     * sole blocker. The reservation is reported in the Verdict so the adapter
     * can finalize it on execution success or roll it back otherwise.
     */
    case Claim;
}
