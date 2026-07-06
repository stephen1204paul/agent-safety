<?php

/**
 * Test-only stand-in for mcp-adapter's public observability contract, when
 * mcp-adapter itself isn't installed in the environment running these tests.
 *
 * Method signature copied VERBATIM from upstream HEAD:
 * includes/Infrastructure/Observability/Contracts/McpObservabilityHandlerInterface.php
 * (namespace `WP\MCP\Infrastructure\Observability\Contracts`), so
 * {@see \Specflux\AgentSafety\Plugin\Hooks\McpRequestAuditHandler}, which
 * `implements` the REAL interface, exercises the REAL contract shape in tests.
 */

declare(strict_types=1);

namespace WP\MCP\Infrastructure\Observability\Contracts;

if (!interface_exists(McpObservabilityHandlerInterface::class, false)) {
    interface McpObservabilityHandlerInterface
    {
        /**
         * @param string $event
         * @param array $tags
         * @param float|null $duration_ms
         *
         * @return void
         */
        public function record_event(string $event, array $tags = array(), ?float $duration_ms = null): void;
    }
}
