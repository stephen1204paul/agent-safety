<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Plugin\Support;

/**
 * The host-derived, non-deterministic bits an audit record needs (SPEC §5):
 * ids, timestamp, client IP, actor. Kept out of the pure core so {@see \Specflux\WooAgentSafety\Audit\AuditRecord}
 * stays clock-/RNG-free and unit-testable.
 *
 * `correlation()` is memoized for the lifetime of the PHP request, so every event
 * emitted while handling one agent `tools/call` shares a correlation id — that is
 * what ties a multi-step agent chain together in the log.
 */
final class RequestContext
{
    private static ?string $correlation = null;
    private static bool $tokenResolved = false;
    private static ?string $tokenId = null;

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
     * The WooCommerce REST API key id behind this MCP request (per D20: auth =
     * `X-MCP-API-Key: ck:cs`). We record the key's row id ("key_7"), NOT the secret
     * — tokens, not PANs (D14). Memoized; null when not an API-key request.
     */
    public static function tokenId(): ?string
    {
        if (self::$tokenResolved) {
            return self::$tokenId;
        }
        self::$tokenResolved = true;

        $header = $_SERVER['HTTP_X_MCP_API_KEY'] ?? '';
        if (!is_string($header) || !str_contains($header, ':') || !function_exists('wc_api_hash')) {
            return self::$tokenId = null;
        }

        [$consumerKey] = explode(':', $header, 2);
        $hashed = wc_api_hash($consumerKey);

        global $wpdb;
        if (!isset($wpdb)) {
            return self::$tokenId = null;
        }

        $keyId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
                $hashed
            )
        );

        return self::$tokenId = $keyId ? 'key_' . $keyId : null;
    }

    private static function uuid(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        return bin2hex(random_bytes(16));
    }
}
