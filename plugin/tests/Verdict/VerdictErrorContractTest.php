<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Verdict;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Gate\Decision;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Tests\Fixtures\VerdictErrorFixture;
use Specflux\AgentSafety\Plugin\Verdict\Verdict;
use Specflux\AgentSafety\Policy\Tier;

/**
 * Producer side of the frozen WP_Error contract: {@see Verdict::error()} must
 * emit exactly the shapes in {@see VerdictErrorFixture}. SenroFlux runs the
 * consumer side against the same fixture file.
 */
final class VerdictErrorContractTest extends TestCase
{
    /** @return iterable<string, array{array{code: string, verb: string, tier: ?int, approval_id: ?string, data: array<string, mixed>}}> */
    public static function cases(): iterable
    {
        foreach (VerdictErrorFixture::cases() as $name => $case) {
            yield $name => [$case];
        }
    }

    /**
     * @dataProvider cases
     * @param array{code: string, verb: string, tier: ?int, approval_id: ?string, data: array<string, mixed>} $case
     */
    public function testErrorMatchesTheFixtureExactly(array $case): void
    {
        $tier = $case['tier'] === null ? null : Tier::from($case['tier']);
        $decision = $case['code'] === VerdictErrorFixture::DENY_CODE
            ? Decision::deny('not_in_pack', $tier)
            : Decision::approvalRequired($tier ?? Tier::Irreversible);

        $verdict = new Verdict($case['verb'], new Pack(name: 'support', allow: ['woocommerce/*']), $decision, $case['approval_id']);
        $error = $verdict->error();

        $this->assertNotNull($error);
        $this->assertSame($case['code'], $error->get_error_code());
        $this->assertSame($case['data'], $error->get_error_data(), 'key presence and order are part of the contract');
    }

    public function testAnAllowedVerdictHasNoError(): void
    {
        $verdict = new Verdict('woocommerce/orders-list', new Pack(name: 'support', allow: ['woocommerce/*']), Decision::allow(Tier::Reversible));

        $this->assertNull($verdict->error());
    }

    public function testAShadowedBlockHasNoError(): void
    {
        $verdict = new Verdict('woocommerce/orders-list', new Pack(name: 'support', allow: ['woocommerce/*']), Decision::deny('not_in_pack'), shadowed: true);

        $this->assertNull($verdict->error());
        $this->assertTrue($verdict->proceeds());
    }
}
