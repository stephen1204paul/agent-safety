<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Plugin\Approval\StateProbe;

/**
 * AS-6 state probe for the two order-mutation verbs that overwrite rather
 * than append (`orders-update`, `order-update-status`) — both take an
 * integer `id` argument (verified against WooCommerce 11.1.0's REST bridge
 * and `OrderAbilityTrait::get_order_from_input()`). `order-add-note` has
 * deliberately no probe: it appends to an order rather than overwriting it.
 * Returns the order's modified timestamp and status; null when the order no
 * longer exists.
 */
final class WooOrderStateProbe implements StateProbe
{
    /** @param array<string, mixed> $args */
    public function read(string $verb, array $args): ?array
    {
        $id = isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : 0;
        if ($id < 1) {
            return null;
        }

        $order = wc_get_order($id);
        if ($order === false) {
            return null;
        }

        $modified = $order->get_date_modified();

        return [
            'modified' => $modified !== null ? $modified->getTimestamp() : null,
            'status' => $order->get_status(),
        ];
    }

    /** @param array<string, mixed> $args */
    public function targetArgs(string $verb, array $args): array
    {
        return isset($args['id']) && is_numeric($args['id']) ? ['id' => (int) $args['id']] : [];
    }
}
