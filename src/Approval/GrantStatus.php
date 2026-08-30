<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Approval;

/**
 * Lifecycle of one pre-approval {@see Grant}.
 *
 *   active    → a human authorised N future calls of one verb; reservations left.
 *   exhausted → every reservation has been spent (remaining_count hit 0).
 *   expired   → the hard TTL lapsed before the count ran out.
 *   revoked   → withdrawn out-of-band (the run ended, a human pulled it).
 *
 * Only `active` can ever authorise a call, and only while the grant still has
 * remaining count AND is inside its TTL — see {@see Grant::canReserve()}.
 * Everything else fails closed.
 */
enum GrantStatus: string
{
    case Active = 'active';
    case Exhausted = 'exhausted';
    case Expired = 'expired';
    case Revoked = 'revoked';
}
