<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Gate;

use Specflux\AgentSafety\Policy\Tier;

/**
 * The gate's verdict for one verb call. Immutable; carries a machine-readable
 * reason code for the audit log (SPEC §5) and client error.
 */
final class Decision
{
    private function __construct(
        public readonly Outcome $outcome,
        public readonly string $reason,
        public readonly ?Tier $tier = null,
    ) {
    }

    public static function allow(Tier $tier): self
    {
        return new self(Outcome::Allow, 'allowed', $tier);
    }

    public static function deny(string $reason, ?Tier $tier = null): self
    {
        return new self(Outcome::Deny, $reason, $tier);
    }

    public static function approvalRequired(Tier $tier): self
    {
        return new self(Outcome::ApprovalRequired, 'approval_required', $tier);
    }

    public function isAllowed(): bool
    {
        return $this->outcome === Outcome::Allow;
    }
}
