<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Tests\Policy;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\VerbCatalog;

final class VerbCatalogTest extends TestCase
{
    public function testUnknownVerbIsNullAndUnknown(): void
    {
        $catalog = new VerbCatalog();

        $this->assertNull($catalog->baseTier('anything/at-all'));
        $this->assertFalse($catalog->isKnown('anything/at-all'));
    }

    public function testRegisterAddsExactVerbs(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible]);

        $this->assertSame(Tier::Reversible, $catalog->baseTier('demo/read'));
        $this->assertTrue($catalog->isKnown('demo/read'));
        $this->assertNull($catalog->baseTier('demo/write'));
    }

    public function testRegisterMergesAcrossMultipleCalls(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible]);
        $catalog->register(['demo/write' => Tier::SideEffecting]);

        $this->assertSame(Tier::Reversible, $catalog->baseTier('demo/read'));
        $this->assertSame(Tier::SideEffecting, $catalog->baseTier('demo/write'));
    }

    public function testLaterRegisterOverwritesSameExactKey(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/read' => Tier::Reversible]);
        $catalog->register(['demo/read' => Tier::Irreversible]);

        $this->assertSame(Tier::Irreversible, $catalog->baseTier('demo/read'));
    }

    public function testTrailingStarIsAPrefixPatternWithHyphenBoundary(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register(['demo/reports-*' => Tier::Reversible]);

        $this->assertSame(Tier::Reversible, $catalog->baseTier('demo/reports-sales'));
        // No hyphen boundary -- must not match a verb that merely shares the prefix text.
        $this->assertNull($catalog->baseTier('demo/reportsomething'));
        $this->assertNull($catalog->baseTier('demo/other'));
    }

    public function testExactMatchWinsOverPrefix(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register([
            'demo/settings-*' => Tier::SideEffecting,
            'demo/settings-danger' => Tier::Irreversible,
        ]);

        $this->assertSame(Tier::Irreversible, $catalog->baseTier('demo/settings-danger'));
        $this->assertSame(Tier::SideEffecting, $catalog->baseTier('demo/settings-other'));
    }

    public function testLongestMatchingPrefixWins(): void
    {
        $catalog = new VerbCatalog();
        $catalog->register([
            'demo/*' => Tier::Reversible,
            'demo/settings-*' => Tier::Irreversible,
        ]);

        $this->assertSame(Tier::Irreversible, $catalog->baseTier('demo/settings-danger'));
        $this->assertSame(Tier::Reversible, $catalog->baseTier('demo/other-thing'));
    }
}
