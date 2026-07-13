<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Audit;

use Specflux\AgentSafety\Gate\Outcome;

/**
 * The audit-log `decision` field. Distinct from {@see Outcome}: the gate
 * emits an Outcome synchronously, but the audit trail also records lifecycle states
 * that the gate never returns (an approval being granted or rejected out-of-band).
 */
enum AuditDecision: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Pending = 'pending';   // approval required, awaiting a human
    case Approved = 'approved'; // a human granted a pending request
    case Rejected = 'rejected'; // a human refused a pending request

    /** Map a synchronous gate verdict to its audit decision. */
    public static function fromOutcome(Outcome $outcome): self
    {
        return match ($outcome) {
            Outcome::Allow => self::Allowed,
            Outcome::Deny => self::Denied,
            Outcome::ApprovalRequired => self::Pending,
        };
    }
}
