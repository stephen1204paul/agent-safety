<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Approval\StateProbe;
use Specflux\AgentSafety\Plugin\Support\StateProbes;

/**
 * The `agent_safety_state_probes` seam (AS-6): mirrors
 * {@see \Specflux\AgentSafety\Plugin\Tests\Support\ElevationRulesTest} exactly
 * — a site may add a probe for a verb no module already declared one for, but
 * can never override or remove a module's own.
 */
final class StateProbesTest extends TestCase
{
    protected function setUp(): void
    {
        remove_all_filters(StateProbes::FILTER);
    }

    protected function tearDown(): void
    {
        remove_all_filters(StateProbes::FILTER);
    }

    private function probe(): StateProbe
    {
        return new class implements StateProbe {
            public function read(string $verb, array $args): ?array
            {
                return ['ok' => true];
            }
        };
    }

    public function testAnUnhookedFilterChangesNothing(): void
    {
        $module = $this->probe();

        $this->assertSame(['demo/a' => $module], StateProbes::filtered(['demo/a' => $module]));
    }

    public function testAContributedProbeForAnUnclaimedVerbIsAdded(): void
    {
        $module = $this->probe();
        $site = $this->probe();
        add_filter(StateProbes::FILTER, static fn (array $probes): array => [...$probes, 'demo/b' => $site]);

        $result = StateProbes::filtered(['demo/a' => $module]);

        $this->assertSame($module, $result['demo/a']);
        $this->assertSame($site, $result['demo/b']);
    }

    public function testAFilterCannotOverrideAModulesOwnProbeForAVerb(): void
    {
        $module = $this->probe();
        $impostor = $this->probe();
        add_filter(StateProbes::FILTER, static fn (array $probes): array => [...$probes, 'demo/a' => $impostor]);

        $result = StateProbes::filtered(['demo/a' => $module]);

        $this->assertSame($module, $result['demo/a'], 'a module-declared probe must never be replaced by the filter');
        $this->assertCount(1, $result);
    }

    public function testAFilterCannotRemoveAModulesOwnProbe(): void
    {
        $module = $this->probe();
        add_filter(StateProbes::FILTER, static fn (): array => []);

        $this->assertSame(['demo/a' => $module], StateProbes::filtered(['demo/a' => $module]));
    }

    public function testANonProbeEntryIsDroppedRatherThanTrusted(): void
    {
        $module = $this->probe();
        add_filter(StateProbes::FILTER, static fn (array $probes): array => [
            ...$probes,
            'demo/b' => 'not-a-probe',
            'demo/c' => new \stdClass(),
            'demo/d' => null,
        ]);

        $this->assertSame(['demo/a' => $module], StateProbes::filtered(['demo/a' => $module]));
    }

    public function testANonStringKeyIsDroppedRatherThanTrusted(): void
    {
        $module = $this->probe();
        $site = $this->probe();
        add_filter(StateProbes::FILTER, static function (array $probes) use ($site): array {
            $probes[] = $site; // integer key

            return $probes;
        });

        $this->assertSame(['demo/a' => $module], StateProbes::filtered(['demo/a' => $module]));
    }

    public function testANonArrayFilterReturnIsIgnored(): void
    {
        $module = $this->probe();
        add_filter(StateProbes::FILTER, static fn (): string => 'nope');

        $this->assertSame(['demo/a' => $module], StateProbes::filtered(['demo/a' => $module]));
    }
}
