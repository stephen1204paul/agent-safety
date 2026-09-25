<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Plugin\Approval\StateProbe;

/**
 * AS-6 state probe for the four product-mutation verbs
 * (`products-update`, `products-delete`, `product-update`, `product-delete`)
 * — all four take an integer `id` argument (verified against WooCommerce
 * 11.1.0's REST bridge and session-visible ability input schemas). Returns
 * the product's modified timestamp and status; null when the product no
 * longer exists, which the pipeline treats as `state_unverifiable`.
 */
final class WooProductStateProbe implements StateProbe
{
    /** @param array<string, mixed> $args */
    public function read(string $verb, array $args): ?array
    {
        $id = isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : 0;
        if ($id < 1) {
            return null;
        }

        $product = wc_get_product($id);
        if ($product === false || $product === null) {
            return null;
        }

        $modified = $product->get_date_modified();

        return [
            'modified' => $modified !== null ? $modified->getTimestamp() : null,
            'status' => $product->get_status(),
        ];
    }

    /** @param array<string, mixed> $args */
    public function targetArgs(string $verb, array $args): array
    {
        return isset($args['id']) && is_numeric($args['id']) ? ['id' => (int) $args['id']] : [];
    }
}
