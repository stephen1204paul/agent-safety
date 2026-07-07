<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Hooks;

use Specflux\AgentSafety\Audit\AuditRecord;
use Specflux\AgentSafety\Audit\AuditSink;
use Specflux\AgentSafety\Audit\Redactor;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Plugin\Support\ExecutionResult;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;

/**
 * Audits every ability that EXECUTES (SPEC §5) — both successes and failures,
 * independent of the mcp-adapter version.
 *
 * Core's Abilities API fires `wp_before_execute_ability` for every permitted run,
 * but `wp_after_execute_ability` ONLY on success (it early-returns when
 * `do_execute()` or output validation yields a WP_Error). We exploit that asymmetry:
 *   - before   → mark the call in-flight (stash its validated input);
 *   - after    → success: write the record and clear the in-flight mark;
 *   - shutdown → anything still in-flight never reached `after`, so it failed (a
 *                WP_Error result, an invalid output, or a fatal — `shutdown` runs
 *                even after fatals); write it as result=failure.
 *
 * This captures execution failures with no dependency on the v0.5.0
 * `mcp_adapter_tool_call_result` filter (absent from Woo's vendored 0.1.0).
 *
 * Denied / approval-pending calls never reach these hooks — they are blocked in the
 * permission callback and audited there ({@see AbilityPermissionGate}).
 *
 * In-flight is a per-name LIFO stack, so nested ability calls pair correctly
 * (before A, before B, after B, after A).
 */
final class AbilityAuditLog
{
    /** @var array<string, list<array<string, mixed>>> name => stack of inputs */
    private array $inFlight = [];

    /**
     * @param list<string> $governedNamespaces Ability-id prefixes this hook audits
     *                                          (contributed by integrations + the
     *                                          `agent_safety_governed_namespaces`
     *                                          filter). Empty => inert no-op.
     */
    public function __construct(
        private readonly AuditSink $sink,
        private readonly TierClassifier $classifier,
        private readonly PackResolver $packs,
        private readonly array $governedNamespaces = [],
    ) {
    }

    public function register(): void
    {
        add_action('wp_before_execute_ability', [$this, 'before'], 10, 2);
        add_action('wp_after_execute_ability', [$this, 'after'], 10, 3);
        // Flush any execution that started but never completed (failed / fatal).
        add_action('shutdown', [$this, 'flushFailures'], 0);
    }

    /** @param mixed $input */
    public function before(string $name, $input): void
    {
        if (!$this->isGoverned($name)) {
            return;
        }
        $this->inFlight[$name][] = is_array($input) ? $input : [];
    }

    /**
     * @param mixed $input
     * @param mixed $result
     */
    public function after(string $name, $input, $result): void
    {
        if (!$this->isGoverned($name)) {
            return;
        }

        $args = $this->popInFlight($name) ?? (is_array($input) ? $input : []);
        $this->write($name, $args, ExecutionResult::isFailure($result) ? 'failure' : 'success');
    }

    /** Shutdown handler: everything left in-flight failed to complete. */
    public function flushFailures(): void
    {
        foreach ($this->inFlight as $name => $stack) {
            foreach ($stack as $input) {
                $this->write($name, $input, 'failure');
            }
        }
        $this->inFlight = [];
    }

    /** Is this ability name under one of the governed namespace prefixes? */
    private function isGoverned(string $name): bool
    {
        foreach ($this->governedNamespaces as $namespace) {
            if ($namespace !== '' && str_starts_with($name, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function write(string $name, array $args, string $result): void
    {
        $pack = $this->packs->resolve();
        $tier = $this->classifier->classify($name, $args)?->value;

        $this->sink->append(AuditRecord::execution(
            id: RequestContext::event(),
            ts: RequestContext::nowUtc(),
            correlationId: RequestContext::correlation(),
            pack: $pack->name,
            actor: RequestContext::actor(),
            ability: $name,
            tier: $tier,
            input: Redactor::apply($args, $pack->redactsPii()),
            result: $result,
            externalEffects: self::effectsFor($tier),
            ip: RequestContext::ip(),
        ));
    }

    /**
     * Pop the most recent in-flight input for an ability (LIFO).
     *
     * @return array<string, mixed>|null
     */
    private function popInFlight(string $name): ?array
    {
        if (empty($this->inFlight[$name])) {
            return null;
        }
        $input = array_pop($this->inFlight[$name]);
        if (empty($this->inFlight[$name])) {
            unset($this->inFlight[$name]);
        }

        return $input;
    }

    /**
     * Coarse "what left the box" signal (SPEC §5 external_effects; D5). Refine
     * per-verb later (e.g. refund → psp.refund, email).
     *
     * @return list<string>
     */
    private static function effectsFor(?int $tier): array
    {
        if ($tier === null || $tier === 0) {
            return [];
        }
        if ($tier >= 2) {
            return ['wc.write', 'wc.irreversible'];
        }

        return ['wc.write'];
    }
}
