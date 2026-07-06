<?php

declare(strict_types=1);

namespace Specflux\WooAgentSafety\Tests;

use PHPUnit\Framework\TestCase;
use Specflux\WooAgentSafety\Approval\ApprovalBinding;

final class ApprovalBindingTest extends TestCase
{
    public function testIsDeterministic(): void
    {
        $a = ApprovalBinding::hash('woocommerce/orders-update', ['id' => 1, 'status' => 'completed']);
        $b = ApprovalBinding::hash('woocommerce/orders-update', ['id' => 1, 'status' => 'completed']);

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a), 'sha256 hex digest');
    }

    public function testTokenArgIsExcludedFromBinding(): void
    {
        // The pre-approval call (no token) and the retry (with token) MUST hash
        // identically — otherwise the minted token could never match on retry.
        $without = ApprovalBinding::hash('woocommerce/orders-update', ['id' => 1, 'status' => 'completed']);
        $with = ApprovalBinding::hash('woocommerce/orders-update', [
            'id' => 1,
            'status' => 'completed',
            '_approval' => 'apt_deadbeef',
        ]);

        $this->assertSame($without, $with);
    }

    public function testKeyOrderDoesNotChangeHash(): void
    {
        $one = ApprovalBinding::hash('woocommerce/orders-update', ['id' => 1, 'status' => 'completed']);
        $two = ApprovalBinding::hash('woocommerce/orders-update', ['status' => 'completed', 'id' => 1]);

        $this->assertSame($one, $two);
    }

    public function testNestedKeyOrderDoesNotChangeHash(): void
    {
        $one = ApprovalBinding::hash('woocommerce/orders-update', ['meta' => ['b' => 2, 'a' => 1]]);
        $two = ApprovalBinding::hash('woocommerce/orders-update', ['meta' => ['a' => 1, 'b' => 2]]);

        $this->assertSame($one, $two);
    }

    public function testListOrderIsSignificant(): void
    {
        // Positional lists are NOT reordered — [1,2] and [2,1] are different actions.
        $one = ApprovalBinding::hash('woocommerce/products-delete', ['ids' => [1, 2]]);
        $two = ApprovalBinding::hash('woocommerce/products-delete', ['ids' => [2, 1]]);

        $this->assertNotSame($one, $two);
    }

    public function testDifferentVerbOrArgsChangeHash(): void
    {
        $base = ApprovalBinding::hash('woocommerce/orders-update', ['id' => 1, 'status' => 'completed']);
        $otherVerb = ApprovalBinding::hash('woocommerce/orders-delete', ['id' => 1, 'status' => 'completed']);
        $otherArgs = ApprovalBinding::hash('woocommerce/orders-update', ['id' => 2, 'status' => 'completed']);

        $this->assertNotSame($base, $otherVerb);
        $this->assertNotSame($base, $otherArgs);
    }
}
