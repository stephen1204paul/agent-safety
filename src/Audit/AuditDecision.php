<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Audit;

use Specflux\AgentSafety\Gate\Outcome;

/**
 * The audit-log `decision` field. Distinct from {@see Outcome}: the gate
 * emits an Outcome synchronously, but the audit trail also records lifecycle states
 * that the gate never returns (an approval being granted or rejected out-of-band,
 * or a pre-approval grant being issued/revoked/exhausted).
 *
 * `Grant` rows carry the specific event name in the record's `reason` field
 * (`grant.issued`, `grant.revoked`, `grant.exhausted`) rather than as their own
 * decision values: the grant lifecycle is one auditable subsystem, and keeping it
 * to a single decision value means a reader can select every grant event with one
 * predicate while still telling the three apart. `Admin` rows follow the same
 * scheme for configuration changes made by a human (`shadow.enabled`,
 * `binding.changed`, ...): the things that decide how every later call is judged
 * belong in the same tamper-evident chain as the calls themselves.
 */
enum AuditDecision: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Pending = 'pending';   // approval required, awaiting a human
    case Approved = 'approved'; // a human granted a pending request
    case Rejected = 'rejected'; // a human refused a pending request
    case Grant = 'grant';       // a pre-approval grant's own lifecycle (AS-12)
    case Admin = 'admin';       // a change to the plugin's own configuration
    case Stale = 'stale';       // an approval whose target changed before it could be claimed or re-filed (AS-6)

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
