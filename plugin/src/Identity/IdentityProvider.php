<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Identity;

/**
 * A source of principal identity for the CURRENT request, plus the bindable
 * catalog of identities of that kind for the admin Packs UI. Multiple
 * providers can apply to one request (e.g. a logged-in user
 * who is also a member of a role); {@see \Specflux\AgentSafety\Plugin\Identity\IdentityChain}
 * concatenates them in a fixed, host-configured order.
 */
interface IdentityProvider
{
    /**
     * Ordered candidate token ids for THIS request, most-specific first (e.g.
     * a concrete user before its role). Empty when this provider does not
     * apply to the current request at all.
     *
     * @return list<string>
     */
    public function currentTokens(): array;

    /**
     * Every token id this provider could ever produce, for the admin binding
     * UI — NOT scoped to the current request.
     *
     * @return array<string, string> token id => human label
     */
    public function bindableTokens(): array;

    /** Section heading for the admin Capability Packs binding UI. */
    public function label(): string;
}
