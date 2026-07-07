<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Gate;
use Specflux\AgentSafety\Plugin\Hooks\AbilityPermissionGate;
use Specflux\AgentSafety\Plugin\Support\DecisionRecorder;
use Specflux\AgentSafety\Plugin\Support\PackResolver;

/**
 * Exercises the governed-namespace gate behaviour (SPEC seam 6): {@see
 * AbilityPermissionGate::wrap()} must be a complete no-op for any ability name
 * outside the injected namespace list, and an inert no-op for EVERYTHING when
 * that list is empty (a site with no integration active).
 */
final class AbilityPermissionGateTest extends TestCase
{
    private function gate(array $governedNamespaces): AbilityPermissionGate
    {
        return new AbilityPermissionGate(
            new Gate(),
            new PackResolver(),
            new DecisionRecorder(),
            null,
            $governedNamespaces,
        );
    }

    public function testUngovernedNamespaceLeavesArgsAndCallbackUntouched(): void
    {
        $gate = $this->gate(['woocommerce/']);
        $original = static fn () => true;
        $args = ['permission_callback' => $original];

        $wrapped = $gate->wrap($args, 'core/something-else');

        $this->assertSame($args, $wrapped);
        $this->assertSame($original, $wrapped['permission_callback']);
    }

    public function testGovernedNamespaceReplacesThePermissionCallback(): void
    {
        $gate = $this->gate(['woocommerce/']);
        $original = static fn () => true;
        $args = ['permission_callback' => $original];

        $wrapped = $gate->wrap($args, 'woocommerce/orders-list');

        $this->assertNotSame($original, $wrapped['permission_callback']);
        $this->assertIsCallable($wrapped['permission_callback']);
    }

    public function testEmptyGovernedNamespacesIsInertForEveryAbility(): void
    {
        $gate = $this->gate([]);
        $original = static fn () => true;
        $args = ['permission_callback' => $original];

        $wrapped = $gate->wrap($args, 'woocommerce/orders-list');

        $this->assertSame($args, $wrapped);
    }

    public function testMultipleGovernedNamespacesEachApply(): void
    {
        $gate = $this->gate(['woocommerce/', 'custom-integration/']);
        $original = static fn () => true;

        $wrappedWoo = $gate->wrap(['permission_callback' => $original], 'woocommerce/orders-list');
        $wrappedCustom = $gate->wrap(['permission_callback' => $original], 'custom-integration/do-thing');
        $wrappedOther = $gate->wrap(['permission_callback' => $original], 'other/thing');

        $this->assertNotSame($original, $wrappedWoo['permission_callback']);
        $this->assertNotSame($original, $wrappedCustom['permission_callback']);
        $this->assertSame($original, $wrappedOther['permission_callback']);
    }
}
