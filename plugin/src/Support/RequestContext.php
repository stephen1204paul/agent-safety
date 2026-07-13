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
