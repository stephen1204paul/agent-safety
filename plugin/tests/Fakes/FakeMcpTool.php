<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Fakes;

/**
 * Minimal stand-in for mcp-adapter's McpTool, duck-typed to the exact accessor
 * chain {@see \Specflux\AgentSafety\Plugin\Hooks\PreToolCallGate} reads:
 * get_protocol_dto()->getAnnotations()?->getReadOnlyHint()/getDestructiveHint().
 * The real WP\MCP\Domain\Tools\McpTool is `final` with a private constructor
 * (factory methods only), so it cannot be instantiated or mocked directly in a
 * plain PHPUnit run without a full WP/mcp-adapter boot — this fixture drives
 * the same accessor shape instead. get_protocol_dto() returns $this (rather
 * than a distinct DTO object) purely for fixture simplicity; PreToolCallGate's
 * duck-typing only ever calls method_exists() + the accessor, so it cannot
 * tell the difference.
 *
 * Also stands in for the `get_observability_context()` accessor
 * {@see \Specflux\AgentSafety\Plugin\Hooks\ToolCallResultRedactor} reads —
 * upstream's `McpTool::fromAbility()` puts the real ability id there for any
 * ability-backed tool (`['ability_name' => $ability->get_name(), ...]`).
 */
final class FakeMcpTool
{
    private function __construct(
        private readonly ?object $annotations,
        private readonly ?string $abilityName = null,
    ) {
    }

    /** A tool whose annotations report the given hints (both default: absent, i.e. no annotations at all). */
    public static function withHints(?bool $readOnlyHint = null, ?bool $destructiveHint = null): self
    {
        $hasAHint = $readOnlyHint !== null || $destructiveHint !== null;

        return new self($hasAHint ? new FakeToolAnnotations($readOnlyHint, $destructiveHint) : null);
    }

    /** A tool whose annotations object is caller-supplied — for malformed/foreign-shape fixtures. */
    public static function withAnnotations(?object $annotations): self
    {
        return new self($annotations);
    }

    /** An ability-backed tool whose observability context carries the given ability id. */
    public static function withAbility(string $abilityName): self
    {
        return new self(null, $abilityName);
    }

    public function get_protocol_dto(): self
    {
        return $this;
    }

    public function getAnnotations(): ?object
    {
        return $this->annotations;
    }

    /** @return array<string, mixed> */
    public function get_observability_context(): array
    {
        return $this->abilityName !== null ? ['ability_name' => $this->abilityName] : [];
    }
}
