<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

/**
 * Normalises an order status argument the way WooCommerce does before it
 * acts on it: the `woocommerce/order-update-status` ability runs
 * `OrderUtil::remove_status_prefix( sanitize_key( $status ) )`. Any
 * comparison of a caller-supplied order status must go through here, or
 * `wc-completed` (or `WC-Completed`, ` wc-completed `) completes the order
 * while looking like an unknown status to us.
 *
 * Pure PHP on purpose: sanitize_key() is `[^a-z0-9_\-]` stripped after
 * lowercasing, reproduced here so the core and tests need no WordPress.
 */
final class OrderStatus
{
    public static function normalize(mixed $status): ?string
    {
        if (!is_string($status)) {
            return null;
        }

        $key = self::sanitizeKey($status);
        if (str_starts_with($key, 'wc-')) {
            $key = substr($key, 3);
        }

        return $key;
    }

    /** WordPress sanitize_key(): lowercase, then strip everything but a-z 0-9 _ - (so whitespace goes too). */
    public static function sanitizeKey(string $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)) ?? '';
    }
}
