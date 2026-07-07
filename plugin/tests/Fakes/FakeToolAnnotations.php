<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

/**
 * Minimal stand-in for mcp-adapter's ToolAnnotations DTO, duck-typed to the
 * two accessors {@see \Specflux\AgentSafety\Plugin\Hooks\PreToolCallGate}
 * reads: getReadOnlyHint() and getDestructiveHint().
 */
final class FakeToolAnnotations
{
    public function __construct(
        private readonly ?bool $readOnlyHint = null,
        private readonly ?bool $destructiveHint = null,
    ) {
    }

    public function getReadOnlyHint(): ?bool
    {
        return $this->readOnlyHint;
    }

    public function getDestructiveHint(): ?bool
    {
        return $this->destructiveHint;
    }
}
