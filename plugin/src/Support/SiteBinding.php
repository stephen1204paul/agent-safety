<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Support;

/**
 * Pure normalisation of a site's `home_url()` into the Site binding (§3.9,
 * §3.4 item 2): host + port + path, with the scheme deliberately dropped —
 * an http-to-https migration is not a site move, but `/shop` and `/staging`
 * are different sites.
 *
 * No WordPress calls: {@see \Specflux\AgentSafety\Plugin\Support\EnvironmentGuard}
 * is the only caller and supplies the raw `home_url()` string, so this stays
 * a pure, exhaustively unit-testable function.
 */
final class SiteBinding
{
    /**
     * @param string $homeUrl The raw return of `home_url()` (or any URL to compare the same way).
     */
    public static function normalize(string $homeUrl): string
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- deliberately WP-free (see the class docblock); wp_parse_url() would pull a WordPress dependency into the one caller's-supplied-string pure function this class exists to be.
        $parts = parse_url(trim($homeUrl));
        if (!is_array($parts)) {
            // Unparseable input normalises to itself, lowercased: still
            // comparable, and never silently treated as "no site" (an empty
            // string would collide with every other unparseable value).
            return strtolower(trim($homeUrl));
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = self::idnAscii($host);

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = $parts['port'] ?? null;
        // Scheme is ignored entirely (item 2), but an EXPLICIT default port
        // (":80" on http, ":443" on https) is treated as equivalent to no
        // port at all — conservatively, since guessing a "default" port for
        // an unknown/absent scheme would be just that, a guess. Any other
        // explicit port is kept and DOES distinguish two sites.
        $isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        $portSuffix = ($port !== null && !$isDefaultPort) ? ':' . $port : '';

        $path = (string) ($parts['path'] ?? '');
        // Trailing slash normalised away; an empty/root path becomes ''
        // rather than '/', so "example.com" and "example.com/" compare equal.
        $path = rtrim($path, '/');

        return $host . $portSuffix . $path;
    }

    /**
     * IDN-ASCII the host when the intl extension is available; otherwise the
     * lowercased host is left as-is (an accurate, explicit degrade rather
     * than a half-normalisation) — see the class doc for why this is
     * conservative rather than a bug.
     */
    private static function idnAscii(string $host): string
    {
        if ($host === '' || !function_exists('idn_to_ascii')) {
            return $host;
        }

        $ascii = idn_to_ascii($host, 0, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) && $ascii !== '' ? $ascii : $host;
    }
}
