<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeMcpTool;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeToolAnnotations;
use Specflux\AgentSafety\Plugin\Verdict\Hints;
use stdClass;

/**
 * READING self-reported annotations off the two very different shapes the two
 * gate seams are handed: an Abilities API registration array
 * (`meta.annotations.*`, whose values are `bool|null` per WP core's
 * wp_register_ability() docs) and mcp-adapter's $mcp_tool object.
 *
 * What the hints then DO to a decision belongs to the pipeline, and is proven
 * once in {@see VerdictPipelineTest}. What matters here is the parsing rule:
 * anything that is not literally `true` reads as "no hint", and no malformed
 * or foreign input may fatal — a hint is untrusted input from a third-party
 * plugin, so failing to read one must degrade to "we were told nothing"
 * (which is the strict side: the readonly hint only ever ADDS a denial, and a
 * missing destructive hint just leaves our own classification in charge).
 */
final class HintsTest extends TestCase
{
    // --- Abilities API registration args -------------------------------------

    public function testAbilityArgsReadBothAnnotationsWhenTrue(): void
    {
        $hints = Hints::fromAbilityArgs(['meta' => ['annotations' => ['readonly' => true, 'destructive' => true]]]);

        $this->assertTrue($hints->readonly);
        $this->assertTrue($hints->destructive);
    }

    public function testAbilityArgsReadEachAnnotationIndependently(): void
    {
        $destructive = Hints::fromAbilityArgs(['meta' => ['annotations' => ['destructive' => true]]]);

        $this->assertTrue($destructive->destructive);
        $this->assertFalse($destructive->readonly, 'an absent annotation is not the opposite one');
    }

    public function testAbilityArgsTreatNullAndFalseAnnotationsAsNoHint(): void
    {
        // wp_register_ability() documents these as bool|null: an explicit null
        // is "unspecified", which is the same as absent for our purposes.
        $hints = Hints::fromAbilityArgs(['meta' => ['annotations' => ['readonly' => null, 'destructive' => false]]]);

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }

    public function testAbilityArgsTreatTruthyNonBooleansAsNoHint(): void
    {
        // Strict === true only: a stringly-typed "true" or a 1 from a sloppy
        // registration must not be able to elevate anything by accident.
        $hints = Hints::fromAbilityArgs(['meta' => ['annotations' => ['readonly' => 'true', 'destructive' => 1]]]);

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }

    public function testAbilityArgsWithoutMetaOrAnnotationsAreNoHint(): void
    {
        $noMeta = Hints::fromAbilityArgs(['label' => 'Do a thing']);
        $emptyMeta = Hints::fromAbilityArgs(['meta' => []]);

        $this->assertFalse($noMeta->readonly);
        $this->assertFalse($noMeta->destructive);
        $this->assertFalse($emptyMeta->destructive);
    }

    public function testAbilityArgsWithMalformedMetaAreNoHintRatherThanAFatal(): void
    {
        // `meta` (or `meta.annotations`) of the wrong type entirely -- a scalar,
        // a list -- must read as "no hint", never a TypeError inside a
        // registration filter that runs for EVERY ability on the site.
        $scalarMeta = Hints::fromAbilityArgs(['meta' => 'nonsense']);
        $scalarAnnotations = Hints::fromAbilityArgs(['meta' => ['annotations' => 'nonsense']]);
        $listAnnotations = Hints::fromAbilityArgs(['meta' => ['annotations' => ['readonly', 'destructive']]]);

        $this->assertFalse($scalarMeta->readonly);
        $this->assertFalse($scalarMeta->destructive);
        $this->assertFalse($scalarAnnotations->destructive);
        $this->assertFalse($listAnnotations->destructive);
    }

    // --- mcp-adapter $mcp_tool ------------------------------------------------

    public function testMcpToolReadsTheAnnotationAccessorChain(): void
    {
        $hints = Hints::fromMcpTool(FakeMcpTool::withHints(readOnlyHint: true, destructiveHint: true));

        $this->assertTrue($hints->readonly);
        $this->assertTrue($hints->destructive);
    }

    public function testMcpToolTreatsExplicitlyFalseHintsAsNoHint(): void
    {
        $hints = Hints::fromMcpTool(FakeMcpTool::withAnnotations(new FakeToolAnnotations(false, false)));

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }

    public function testNoMcpToolAtAllIsNoHint(): void
    {
        // The filter's $mcp_tool argument is optional on older adapter versions.
        $hints = Hints::fromMcpTool(null);

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }

    public function testMcpToolWithNullAnnotationsDtoIsNoHint(): void
    {
        $hints = Hints::fromMcpTool(FakeMcpTool::withHints());

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }

    public function testAnnotationsObjectMissingTheHintMethodsIsNoHint(): void
    {
        // An "annotations" object of some foreign/older shape with neither hint
        // accessor at all -> duck-typing must fall back to "no hint", not fatal.
        $hints = Hints::fromMcpTool(FakeMcpTool::withAnnotations(new stdClass()));

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }

    public function testForeignMcpToolObjectNeverFatals(): void
    {
        // A completely unrelated object standing in for a different adapter
        // version's $mcp_tool shape -> every duck-typing hop must bail out safely.
        $hints = Hints::fromMcpTool(new stdClass());

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }

    public function testHintsNoneIsBothFalse(): void
    {
        $hints = Hints::none();

        $this->assertFalse($hints->readonly);
        $this->assertFalse($hints->destructive);
    }
}
