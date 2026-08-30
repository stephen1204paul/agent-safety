<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

use Specflux\AgentSafety\Plugin\Identity\IdentityChain;

/**
 * The host-derived, non-deterministic bits an audit record needs:
 * ids, timestamp, client IP, actor. Kept out of the pure core so {@see \Specflux\AgentSafety\Audit\AuditRecord}
 * stays clock-/RNG-free and unit-testable.
 *
 * `correlation()` is memoized for the lifetime of the PHP request, so every event
 * emitted while handling one agent `tools/call` shares a correlation id — that is
 * what ties a multi-step agent chain together in the log.
 *
 * Identity resolution is delegated to an {@see IdentityChain} configured once
 * from the plugin bootstrap ({@see configure()}) — this class holds no opinion
 * about WHICH identity providers exist (application passwords, users/roles, a
 * WooCommerce API key, ...), only that SOMETHING configured the chain.
 */
final class RequestContext
{
    private static ?string $correlation = null;
    private static ?IdentityChain $identity = null;

    /** @var list<string>|null */
    private static ?array $tokens = null;

    /** Wire the identity chain the plugin bootstrap assembled. */
    public static function configure(IdentityChain $identity): void
    {
        self::$identity = $identity;
        self::$tokens = null;
    }

    /** Forget all memoized state (tests only). */
    public static function reset(): void
    {
        self::$correlation = null;
        self::$identity = null;
        self::$tokens = null;
    }

    public static function correlation(): string
    {
        if (self::$correlation === null) {
            self::$correlation = 'sess_' . self::uuid();
        }

        return self::$correlation;
    }

    /**
     * Run $fn with the correlation id pinned to $id, restoring whatever was
     * there before in a `finally` (AS-12). Returns $fn's return value.
     *
     * SCOPED, not once-per-request, and that is the whole point. Ticks are not
     * one-per-process: PHPUnit, WP-CLI, wp-cron and a batch runner all drive
     * several of a host's runs inside one PHP lifetime. A one-shot setter would
     * leave the SECOND run executing under the first run's correlation — and
     * therefore against the first run's grants. Restoring in `finally` means two
     * runs ticked in one process can never see each other's.
     *
     * Precondition: no `correlation()` read may have memoized a DIFFERENT id
     * yet (McpRequestAuditHandler and AbilityAuditLog both read it). If one has,
     * this throws {@see CorrelationConflict} and the caller MUST fail the unit of
     * work it was wrapping — a notice is not a stop. Re-entering with the id that
     * is already pinned is fine (idempotent nesting).
     *
     * SECURITY: with grants enabled the correlation id is half of a grant's match
     * key, so a host MUST derive it from server-side state it owns (a run row's
     * id, e.g. "senroflux:run:42") and NEVER from agent-authored arguments or an
     * HTTP request parameter. There is deliberately no read-time filter for it:
     * a globally hookable correlation id would make the grant key overridable
     * from outside the host.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function withCorrelation(string $id, callable $fn)
    {
        if ($id === '') {
            throw new CorrelationConflict('A correlation id must be a non-empty, host-derived string.');
        }

        if (self::$correlation !== null && self::$correlation !== $id) {
            throw new CorrelationConflict(sprintf(
                'Correlation id "%s" is already in effect for this process; refusing to switch to "%s".',
                self::$correlation,
                $id,
            ));
        }

        $previous = self::$correlation;
        self::$correlation = $id;

        try {
            return $fn();
        } finally {
            self::$correlation = $previous;
        }
    }

    public static function event(): string
    {
        return 'evt_' . self::uuid();
    }

    public static function nowUtc(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function ip(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return is_string($ip) && $ip !== '' ? substr($ip, 0, 45) : null;
    }

    /** @return array{token_id: ?string, wp_user: ?int} */
    public static function actor(): array
    {
        $user = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;

        return ['token_id' => self::tokenId(), 'wp_user' => $user > 0 ? $user : null];
    }

    /**
     * Every current candidate token id for this request, in provider order
     * (most-specific first) — e.g. ["app:{uuid}"] or ["user:5", "role:editor"]
     * or ["wc:key_7"]. Empty when no configured provider applies. Memoized for
     * the request.
     *
     * @return list<string>
     */
    public static function currentTokens(): array
    {
        if (self::$tokens === null) {
            self::$tokens = self::$identity?->currentTokens() ?? [];
        }

        return self::$tokens;
    }

    /**
     * Back-compat single token for the audit actor `token_id` field: the FIRST
     * current candidate, or null when none applies.
     */
    public static function tokenId(): ?string
    {
        $tokens = self::currentTokens();

        return $tokens[0] ?? null;
    }

    private static function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return bin2hex(random_bytes(16));
    }
}
