<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Gate;

use Specflux\WooAgentSafety\Packs\Pack;

/**
 * Everything the gate needs about one inbound verb call. Built by the host
 * (the plugin's mcp_adapter_pre_tool_call handler) from the resolved pack +
 * the tool name + args. The core never touches WordPress globals.
 */
final class GateContext
{
    /**
     * @param string               $verb               Canonical verb id ("woo/orders.refund").
     * @param array<string, mixed> $args               Call arguments.
     * @param Pack                 $pack               Resolved capability pack.
     * @param bool                 $selfReportedReadonly Ability's own annotation (untrusted).
     * @param bool                 $hasValidApproval   Host pre-validated a single-use approval token.
     */
    public function __construct(
        public readonly string $verb,
        public readonly array $args,
        public readonly Pack $pack,
        public readonly bool $selfReportedReadonly = false,
        public readonly bool $hasValidApproval = false,
    ) {
    }
}
