<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Integrations\Woo;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Integrations\Woo\WcApiKeyIdentity;
use wpdb;

final class WcApiKeyIdentityTest extends TestCase
{
    private wpdb $db;

    protected function setUp(): void
    {
        if (!function_exists('wc_api_hash')) {
            eval('function wc_api_hash(string $data): string { return "hashed:" . $data; }');
        }
        $this->db = new wpdb();
        $this->db->rowReturn = ['key_id' => '7', 'consumer_secret' => 'cs_secret'];
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_X_MCP_API_KEY']);
    }

    public function testMatchingKeyAndSecretYieldsTheKeyToken(): void
    {
        $_SERVER['HTTP_X_MCP_API_KEY'] = 'ck_public:cs_secret';

        $this->assertSame(['wc:7'], (new WcApiKeyIdentity($this->db))->currentTokens());
        $this->assertStringContainsString("consumer_key = 'hashed:ck_public'", $this->db->queries[0]);
    }

    public function testWrongSecretYieldsNoIdentity(): void
    {
        $_SERVER['HTTP_X_MCP_API_KEY'] = 'ck_public:not-the-secret';

        $this->assertSame([], (new WcApiKeyIdentity($this->db))->currentTokens());
    }

    public function testEmptySecretOrUnknownKeyYieldsNoIdentity(): void
    {
        $_SERVER['HTTP_X_MCP_API_KEY'] = 'ck_public:';
        $this->assertSame([], (new WcApiKeyIdentity($this->db))->currentTokens());

        $_SERVER['HTTP_X_MCP_API_KEY'] = 'ck_public:cs_secret';
        $this->db->rowReturn = null;
        $this->assertSame([], (new WcApiKeyIdentity($this->db))->currentTokens());
    }
}
