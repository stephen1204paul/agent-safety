<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Audit;

/**
 * One immutable audit event in the PCI-Req-10 shape.
 *
 * Deterministic by design: the host (plugin) injects `id`, `ts`, `correlationId`
 * and `ip` — the core never reads a clock, RNG, or WordPress globals — so records
 * are reproducible under unit test. `input` is expected to be already redacted by
 * the caller per the pack's PII policy ({@see Redactor}).
 */
final class AuditRecord
{
    /**
     * @param array{token_id: ?string, wp_user: ?int} $actor
     * @param array<string, mixed>                     $input            Redacted per pack policy.
     * @param array{id: string, approver: ?int}|null   $approval
     * @param list<string>                             $externalEffects  What left the box (Tier-1 side effects).
     */
    private function __construct(
        public readonly string $id,
        public readonly string $ts,
        public readonly string $correlationId,
        public readonly string $pack,
        public readonly array $actor,
        public readonly string $ability,
        public readonly ?int $tier,
        public readonly array $input,
        public readonly bool $dryRun,
        public readonly AuditDecision $decision,
        public readonly ?array $approval,
        public readonly ?string $result,
        public readonly array $externalEffects,
        public readonly ?string $ip,
    ) {
    }

    /**
     * A gate-decision event: the verdict before (or instead of) execution —
     * denied, pending human approval, or a later approve/reject reconciliation
     * appended to the chain (the log is append-only, so lifecycle states are new
     * rows, never mutations). No `result` yet.
     *
     * @param array{token_id: ?string, wp_user: ?int} $actor
     * @param array<string, mixed>                     $input
     * @param array{id: string, approver: ?int}|null   $approval         The approval this verdict relates to.
     * @param list<string>                             $externalEffects
     */
    public static function decision(
        string $id,
        string $ts,
        string $correlationId,
        string $pack,
        array $actor,
        string $ability,
        ?int $tier,
        array $input,
        AuditDecision $decision,
        ?array $approval = null,
        array $externalEffects = [],
        ?string $ip = null,
    ): self {
        return new self(
            $id,
            $ts,
            $correlationId,
            $pack,
            $actor,
            $ability,
            $tier,
            $input,
            false,
            $decision,
            $approval,
            null,
            $externalEffects,
            $ip,
        );
    }

    /**
     * An execution event: an allowed ability that actually ran. Carries the
     * `result` (success|failure).
     *
     * @param array{token_id: ?string, wp_user: ?int} $actor
     * @param array<string, mixed>                     $input
     * @param list<string>                             $externalEffects
     */
    public static function execution(
        string $id,
        string $ts,
        string $correlationId,
        string $pack,
        array $actor,
        string $ability,
        ?int $tier,
        array $input,
        string $result,
        array $externalEffects = [],
        ?string $ip = null,
    ): self {
        return new self(
            $id,
            $ts,
            $correlationId,
            $pack,
            $actor,
            $ability,
            $tier,
            $input,
            false,
            AuditDecision::Allowed,
            null,
            $result,
            $externalEffects,
            $ip,
        );
    }

    /**
     * Ordered associative array in the canonical audit-record field order. Insertion order is
     * fixed, so {@see canonicalJson()} is deterministic for a given payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'ts' => $this->ts,
            'correlation_id' => $this->correlationId,
            'pack' => $this->pack,
            'actor' => $this->actor,
            'ability' => $this->ability,
            'tier' => $this->tier,
            'input' => $this->input,
            'dry_run' => $this->dryRun,
            'decision' => $this->decision->value,
            'approval' => $this->approval,
            'result' => $this->result,
            'external_effects' => $this->externalEffects,
            'ip' => $this->ip,
        ];
    }

    /** Canonical JSON used as the hash-chain payload. */
    public function canonicalJson(): string
    {
        return (string) json_encode(
            $this->toArray(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
