<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Integrations\Woo;

use Specflux\AgentSafety\Plugin\Identity\IdentityProvider;
use wpdb;

/**
 * Identity provider for the WooCommerce REST API key behind an MCP request
 * (auth = `X-MCP-API-Key: ck:cs`). We record the key's row id
 * ("wc:key_7"), NOT the secret — tokens, not PANs.
 */
final class WcApiKeyIdentity implements IdentityProvider
{
    public function __construct(private readonly wpdb $db)
    {
    }

    /**
     * The header is read raw from the request, so the secret half must be
     * verified here (same rule as WooCommerce's MCP transport): a request
     * authenticated some other way could otherwise name any key it likes and
     * inherit that key's pack binding and rate-limit bucket.
     *
     * @return list<string>
     */
    public function currentTokens(): array
    {
        $header = $_SERVER['HTTP_X_MCP_API_KEY'] ?? '';
        if (!is_string($header) || !str_contains($header, ':') || !function_exists('wc_api_hash')) {
            return [];
        }

        [$consumerKey, $consumerSecret] = explode(':', $header, 2);
        $hashed = wc_api_hash(trim($consumerKey));

        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT key_id, consumer_secret FROM {$this->db->prefix}woocommerce_api_keys WHERE consumer_key = %s",
                $hashed
            ),
            ARRAY_A
        );
        if (!is_array($row) || !is_string($row['consumer_secret'] ?? null) || $row['consumer_secret'] === '') {
            return [];
        }
        if (!hash_equals($row['consumer_secret'], trim($consumerSecret))) {
            return [];
        }

        return ['wc:' . (int) $row['key_id']];
    }

    /**
     * Live WooCommerce REST API keys (each key is a principal). The secret
     * is never read — only the row id, description, and permission scope.
     *
     * @return array<string, string>
     */
    public function bindableTokens(): array
    {
        $table = $this->db->prefix . 'woocommerce_api_keys';
        $rows = $this->db->get_results(
            "SELECT key_id, description, permissions FROM {$table} ORDER BY key_id ASC",
            ARRAY_A
        );
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $keyId = (string) ($r['key_id'] ?? '');
            if ($keyId === '') {
                continue;
            }
            $description = (string) ($r['description'] ?? '');
            $permissions = (string) ($r['permissions'] ?? '');
            $out['wc:' . $keyId] = trim(sprintf('%s (%s)', $description, $permissions), ' ()');
        }

        return $out;
    }

    public function label(): string
    {
        return __('WooCommerce REST API keys — create one under WooCommerce → Settings → Advanced → REST API.', 'agent-safety');
    }
}
