<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

use Specflux\AgentSafety\Plugin\Identity\IdentityProvider;

/** Hand-rolled fake: returns whatever tokens/bindables the test wires up. */
final class FakeIdentityProvider implements IdentityProvider
{
    /**
     * @param list<string>          $currentTokens
     * @param array<string, string> $bindableTokens
     */
    public function __construct(
        private readonly array $currentTokens = [],
        private readonly array $bindableTokens = [],
        private readonly string $label = 'Fake',
    ) {
    }

    public function currentTokens(): array
    {
        return $this->currentTokens;
    }

    public function bindableTokens(): array
    {
        return $this->bindableTokens;
    }

    public function label(): string
    {
        return $this->label;
    }
}
