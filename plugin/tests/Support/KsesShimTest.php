<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Tests the TEST HARNESS, deliberately.
 *
 * The plugin suite runs against a hand-written `wp_kses` shim (tests/bootstrap.php),
 * so the shim's blind spots become the suite's blind spots. The first version of
 * it passed `javascript:` hrefs straight through, which meant the summary-cell
 * tests could go green over a screen that was still injectable. These cases pin
 * the protocol contract the shim borrows from core's wp_kses_bad_protocol().
 *
 * The shim is an APPROXIMATION: the authoritative check remains a wp-env smoke
 * against the real core function.
 */
final class KsesShimTest extends TestCase
{
    /** @var array<string, array<string, bool>> */
    private const ANCHOR_HREF = ['a' => ['href' => true]];

    public function testAJavascriptHrefIsStripped(): void
    {
        $out = wp_kses('<a href="javascript:alert(1)">x</a>', self::ANCHOR_HREF);

        $this->assertSame('<a>x</a>', $out);
        $this->assertStringNotContainsString('javascript', $out);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badProtocolProvider(): array
    {
        return [
            'lowercase javascript' => ['javascript:alert(1)'],
            'mixed case' => ['JaVaScRiPt:alert(1)'],
            'leading whitespace' => ['   javascript:alert(1)'],
            'leading tab' => ["\tjavascript:alert(1)"],
            'embedded newline' => ["java\nscript:alert(1)"],
            'embedded null byte' => ["java\0script:alert(1)"],
            'decimal entity colon' => ['javascript&#58;alert(1)'],
            'padded decimal entity colon' => ['javascript&#0058;alert(1)'],
            'entity colon without semicolon' => ['javascript&#58alert(1)'],
            'hex entity colon' => ['javascript&#x3a;alert(1)'],
            'named entity colon' => ['javascript&colon;alert(1)'],
            'vbscript' => ['vbscript:msgbox(1)'],
            'data url' => ['data:text/html;base64,PHNjcmlwdD4='],
        ];
    }

    /**
     * @dataProvider badProtocolProvider
     */
    public function testBadProtocolsLoseTheHrefAttribute(string $href): void
    {
        $out = wp_kses('<a href="' . $href . '">x</a>', self::ANCHOR_HREF);

        $this->assertSame('<a>x</a>', $out, 'expected the href to be dropped for: ' . $href);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function safeHrefProvider(): array
    {
        return [
            'https' => ['https://example.test/preview'],
            'http' => ['http://example.test/preview'],
            'uppercase scheme' => ['HTTPS://example.test/preview'],
            'mailto' => ['mailto:ops@example.test'],
            'root relative' => ['/wp-admin/post.php?post=42&action=edit'],
            'relative' => ['post.php?post=42'],
            'query only' => ['?page=agent-safety-pending'],
            'anchor only' => ['#row-42'],
            'protocol relative' => ['//example.test/preview'],
            'colon later in a relative path' => ['/preview?next=javascript:alert(1)'],
        ];
    }

    /**
     * @dataProvider safeHrefProvider
     */
    public function testSafeHrefsSurvive(string $href): void
    {
        $this->assertSame(
            '<a href="' . $href . '">x</a>',
            wp_kses('<a href="' . $href . '">x</a>', self::ANCHOR_HREF)
        );
    }

    public function testAnEmptyHrefIsInertAndKept(): void
    {
        $this->assertSame('<a href="">x</a>', wp_kses('<a href="">x</a>', self::ANCHOR_HREF));
    }

    public function testDisallowedTagsAndAttributesStillGo(): void
    {
        $out = wp_kses(
            '<script>alert(1)</script><a href="/ok" onclick="evil()" target="_blank">x</a><strong>b</strong>',
            self::ANCHOR_HREF
        );

        $this->assertSame('alert(1)<a href="/ok">x</a>b', $out);
    }
}
