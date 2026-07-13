<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Identity;

/**
 * Ordered chain of {@see IdentityProvider}s. {@see currentTokens()}
 * concatenates every provider's candidates in registration order, so
 * {@see \Specflux\AgentSafety\Plugin\Support\PackResolver} can walk them and let
 * the first bound token win. Integrations {@see register()} their own provider
 * (e.g. a WooCommerce API-key identity) on top of the always-on providers the
 * bootstrap wires first.
 */
final class IdentityChain
{
    /** @var list<IdentityProvider> */
    private array $providers;

    /** @param list<IdentityProvider> $providers */
    public function __construct(array $providers = [])
    {
        $this->providers = $providers;
    }

    public function register(IdentityProvider $provider): void
    {
        $this->providers[] = $provider;
    }

    /** @return list<string> */
    public function currentTokens(): array
    {
        $tokens = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->currentTokens() as $token) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /** @return list<IdentityProvider> */
    public function providers(): array
    {
        return $this->providers;
    }
}
