<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\ElevationRules;
use Specflux\AgentSafety\Policy\ElevationRule;
use Specflux\AgentSafety\Policy\Tier;
use Specflux\AgentSafety\Policy\TierClassifier;
use Specflux\AgentSafety\Policy\VerbCatalog;

/**
 * The `agent_safety_elevation_rules` seam: a site governing its own namespace
 * can make one of its verbs argument-dependent instead of over-classifying it
 * as irreversible and gating every harmless call on a human.
 */
final class ElevationRulesTest extends TestCase
{
    protected function setUp(): void
    {
        remove_all_filters(ElevationRules::FILTER);
    }

    protected function tearDown(): void
    {
        remove_all_filters(ElevationRules::FILTER);
    }

    private function rule(string $trigger = 'publish'): ElevationRule
    {
        return new class ($trigger) implements ElevationRule {
            public function __construct(private readonly string $trigger)
            {
            }

            public function apply(string $verb, array $args, Tier $currentTier): ?Tier
            {
                return ($args['status'] ?? null) === $this->trigger ? Tier::Irreversible : null;
            }
        };
    }

    public function testAnUnhookedFilterChangesNothing(): void
    {
        $module = $this->rule();

        $this->assertSame([$module], ElevationRules::filtered([$module]));
    }

    public function testAContributedRuleIsAppended(): void
    {
        $module = $this->rule();
        $site = $this->rule('scheduled');
        add_filter(ElevationRules::FILTER, static fn (array $rules): array => [...$rules, $site]);

        $this->assertSame([$module, $site], ElevationRules::filtered([$module]));
    }

    public function testAFilterCannotDropAModulesOwnRule(): void
    {
        // The seam may only ADD. A site that returns an empty array does not get
        // to switch off the elevation the bundled integration modules ship.
        $module = $this->rule();
        add_filter(ElevationRules::FILTER, static fn (): array => []);

        $this->assertSame([$module], ElevationRules::filtered([$module]));
    }

    public function testANonRuleEntryIsDroppedRatherThanTrusted(): void
    {
        // A malformed contribution must degrade to "no extra rule", not fatal
        // inside the classifier on the first governed call.
        $module = $this->rule();
        add_filter(ElevationRules::FILTER, static fn (array $rules): array => [
            ...$rules,
            'not-a-rule',
            new \stdClass(),
            null,
        ]);

        $this->assertSame([$module], ElevationRules::filtered([$module]));
    }

    public function testANonArrayFilterReturnIsIgnored(): void
    {
        $module = $this->rule();
        add_filter(ElevationRules::FILTER, static fn (): string => 'nope');

        $this->assertSame([$module], ElevationRules::filtered([$module]));
    }

    public function testARuleIsNeverDuplicatedWhenTheFilterReturnsItBack(): void
    {
        $module = $this->rule();
        add_filter(ElevationRules::FILTER, static fn (array $rules): array => [...$rules, ...$rules]);

        $this->assertSame([$module], ElevationRules::filtered([$module]));
    }

    public function testAContributedRuleActuallyElevatesTheVerbInTheClassifier(): void
    {
        // End to end: the whole point of the seam is that a site's own verb can
        // be tiered by its ARGUMENTS.
        $catalog = new VerbCatalog();
        $catalog->register(['my-plugin/thing-save' => Tier::Reversible]);
        add_filter(ElevationRules::FILTER, fn (array $rules): array => [...$rules, $this->rule()]);

        $classifier = new TierClassifier($catalog, ElevationRules::filtered([]));

        $this->assertSame(Tier::Reversible, $classifier->classify('my-plugin/thing-save', ['status' => 'draft']));
        $this->assertSame(Tier::Irreversible, $classifier->classify('my-plugin/thing-save', ['status' => 'publish']));
    }

    public function testAContributedRuleCanNeverLowerATier(): void
    {
        // TierClassifier never lets a rule lower a tier, so this seam can only
        // narrow what a credential may do.
        $catalog = new VerbCatalog();
        $catalog->register(['my-plugin/thing-delete' => Tier::Irreversible]);
        $lowering = new class implements ElevationRule {
            public function apply(string $verb, array $args, Tier $currentTier): ?Tier
            {
                return Tier::Reversible;
            }
        };
        add_filter(ElevationRules::FILTER, static fn (array $rules): array => [...$rules, $lowering]);

        $classifier = new TierClassifier($catalog, ElevationRules::filtered([]));

        $this->assertSame(Tier::Irreversible, $classifier->classify('my-plugin/thing-delete', []));
    }
}
