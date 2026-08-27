<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Verdict;

/**
 * A tool's or ability's SELF-REPORTED behavioural annotations, as the adapter
 * saw them. Absent or non-boolean annotations read as false ("no hint").
 *
 * SAFETY-CRITICAL: a hint may only make gating STRICTER, never looser.
 *   - destructive: a call our own classifier placed below the approval tier is
 *     treated as though it HAD classified as irreversible.
 *   - readonly: never relaxes anything; its only effect is the core
 *     {@see \Specflux\AgentSafety\Policy\TierClassifier::isReadonlyButWrites()}
 *     rule, which can only ADD a "readonly_but_writes" denial.
 */
final class Hints
{
    public function __construct(
        public readonly bool $readonly = false,
        public readonly bool $destructive = false,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Hints from an Abilities API registration: `meta.annotations.readonly` and
     * `meta.annotations.destructive` (both `bool|null` per WP core's
     * `wp_register_ability()` docs).
     *
     * @param array<string, mixed> $registrationArgs
     */
    public static function fromAbilityArgs(array $registrationArgs): self
    {
        $meta = $registrationArgs['meta'] ?? null;
        $annotations = is_array($meta) && isset($meta['annotations']) && is_array($meta['annotations'])
            ? $meta['annotations']
            : [];

        return new self(
            readonly: true === ($annotations['readonly'] ?? null),
            destructive: true === ($annotations['destructive'] ?? null),
        );
    }

    /**
     * Hints from mcp-adapter's $mcp_tool via its real accessor chain —
     * get_protocol_dto()->getAnnotations()?->getReadOnlyHint()/getDestructiveHint()
     * — WITHOUT depending on the mcp-adapter classes: every hop is duck-typed
     * and null-guarded, so a foreign or malformed $mcpTool (another adapter
     * version, a plain stdClass in tests) reads as "no hint" rather than fatal.
     *
     * @param mixed $mcpTool
     */
    public static function fromMcpTool($mcpTool): self
    {
        return new self(
            readonly: true === self::annotationHint($mcpTool, 'getReadOnlyHint'),
            destructive: true === self::annotationHint($mcpTool, 'getDestructiveHint'),
        );
    }

    /** @param mixed $mcpTool */
    private static function annotationHint($mcpTool, string $accessor): ?bool
    {
        if (!is_object($mcpTool) || !method_exists($mcpTool, 'get_protocol_dto')) {
            return null;
        }

        $dto = $mcpTool->get_protocol_dto();
        if (!is_object($dto) || !method_exists($dto, 'getAnnotations')) {
            return null;
        }

        $annotations = $dto->getAnnotations();
        if (!is_object($annotations) || !method_exists($annotations, $accessor)) {
            return null;
        }

        $value = $annotations->$accessor();

        return is_bool($value) ? $value : null;
    }
}
