<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Verdict;

/**
 * Which `approval_required` message {@see Verdict::error()} emits (§3.11).
 * Distinct from the frozen error CODE and DATA (ADR 0001, VerdictErrorFixture)
 * — only the human-readable text varies. `SiteMoved` is reserved for stage 7
 * (the environment-binding void path) and is unused until then.
 */
enum ApprovalMessageVariant
{
    case Base;
    case Stale;
    case SiteMoved;
}
