<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Privacy;

use InMemoryAuditRowsWpdb;
use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Audit\AuditReader;
use Specflux\AgentSafety\Plugin\Privacy\PersonalDataEraser;
use Specflux\AgentSafety\Plugin\Privacy\PrivacyAuditReader;

/**
 * §3.6 item 2 / §4 row 9: the eraser must never touch the append-only audit
 * table — a matching user's rows are reported `items_retained`, and the hash
 * chain {@see AuditReader::verifyChain()} must still verify afterwards
 * (nothing was rewritten or dropped to "erase" anything).
 */
final class PersonalDataEraserTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_users_by_email'] = [];
    }

    public function testRetainsMatchingRowsWithoutMutatingTheChain(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain([
            ['wp_user' => 5, 'ability' => 'core/read-post', 'input' => []],
            ['wp_user' => 6, 'ability' => 'core/read-post', 'input' => []],
            ['wp_user' => 5, 'ability' => 'core/update-post', 'input' => []],
        ]));
        $GLOBALS['wpas_test_users_by_email']['alice@example.com'] = 5;

        $this->assertTrue((new AuditReader($db))->verifyChain(), 'chain must be valid before the eraser runs');

        $eraser = new PersonalDataEraser(new PrivacyAuditReader($db));
        $result = $eraser->erase('alice@example.com');

        $this->assertFalse($result['items_removed']);
        $this->assertTrue($result['items_retained']);
        $this->assertNotEmpty($result['messages']);
        $this->assertTrue($result['done']);

        $this->assertTrue(
            (new AuditReader($db))->verifyChain(),
            'chain must still verify after the eraser runs — nothing may be rewritten or dropped'
        );
    }

    public function testUnknownEmailRetainsNothing(): void
    {
        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain([
            ['wp_user' => 5, 'ability' => 'core/read-post', 'input' => []],
        ]));

        $eraser = new PersonalDataEraser(new PrivacyAuditReader($db));
        $result = $eraser->erase('nobody@example.com');

        $this->assertSame(
            ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true],
            $result
        );
    }

    public function testPagesAcrossABatchBoundary(): void
    {
        $specs = [];
        for ($i = 0; $i < 205; $i++) {
            $specs[] = ['wp_user' => 9, 'ability' => 'core/read-post', 'input' => []];
        }

        $db = new InMemoryAuditRowsWpdb();
        $db->seed(PersonalDataExporterTest::chain($specs));
        $GLOBALS['wpas_test_users_by_email']['bob@example.com'] = 9;

        $eraser = new PersonalDataEraser(new PrivacyAuditReader($db));

        $page1 = $eraser->erase('bob@example.com', 1);
        $this->assertFalse($page1['done']);
        $this->assertTrue($page1['items_retained']);

        $page2 = $eraser->erase('bob@example.com', 2);
        $this->assertTrue($page2['done']);
        $this->assertTrue($page2['items_retained']);
    }
}
