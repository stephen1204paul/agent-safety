<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Audit;

/**
 * Masks known PII fields before they enter the audit trail (SPEC §5: "input: PII
 * redacted per pack policy"). Coarse by design — a denylist of WooCommerce PII
 * keys, applied recursively. Tokens, not PANs (D14): we never want a card number,
 * email, or address sitting in the log.
 *
 * This governs what we WRITE TO THE AUDIT LOG. Redacting the payload RETURNED TO
 * THE AGENT on reads is separate, still-pending work.
 */
final class Redactor
{
    /** Lower-cased key fragments that trigger masking on a match. */
    private const PII_KEYS = [
        'email',
        'phone',
        'first_name',
        'last_name',
        'address_1',
        'address_2',
        'postcode',
        'company',
        'ip_address',
    ];

    private const MASK = '«redacted»';

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function apply(array $input, bool $enabled): array
    {
        if (!$enabled) {
            return $input;
        }

        return self::walk($input);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function walk(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::walk($value);
                continue;
            }
            if (is_string($key) && self::isPii($key)) {
                $data[$key] = self::MASK;
            }
        }

        return $data;
    }

    private static function isPii(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::PII_KEYS as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
