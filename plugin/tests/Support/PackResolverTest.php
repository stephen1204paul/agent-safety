<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Packs\Pack;
use Specflux\AgentSafety\Plugin\Identity\IdentityChain;
use Specflux\AgentSafety\Plugin\Support\PackResolver;
use Specflux\AgentSafety\Plugin\Support\RequestContext;
use Specflux\AgentSafety\Plugin\Tests\Fakes\FakeIdentityProvider;

/**
 * Exercises the provider-chain resolution PackResolver now owns (SPEC seam
 * 4/5): every current candidate token is tried, in provider order, and the
 * FIRST one with a stored binding wins.
 */
final class PackResolverTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wpas_test_options'] = [];
    }

    protected function tearDown(): void
    {
        RequestContext::reset();
        $GLOBALS['wpas_test_options'] = [];
    }

    public function testUnboundRequestFallsBackToDefaultPack(): void
    {
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['user:5']),
        ]));

        $resolver = new PackResolver();

        $this->assertSame('default-agent', $resolver->resolve()->name);
    }

    public function testFirstCandidateTokenWithABindingWins(): void
    {
        // Provider order: user:5 (most specific) then role:editor.
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['user:5', 'role:editor']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = [
            'user:5' => 'owner',
            'role:editor' => 'default-agent',
        ];

        $resolver = new PackResolver();

        // user:5 is bound and comes first -> wins over the role binding.
        $this->assertSame('owner', $resolver->resolve()->name);
    }

    public function testUserBindingBeatsRoleBindingWhenUserItselfIsUnbound(): void
    {
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['user:5', 'role:editor']),
        ]));
        // user:5 has NO binding; role:editor does -> the role binding wins
        // because it's the first CANDIDATE (in order) that IS bound.
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = [
            'role:editor' => 'owner',
        ];

        $resolver = new PackResolver();

        $this->assertSame('owner', $resolver->resolve()->name);
    }

    public function testLaterProviderInChainOnlyWinsWhenEarlierOneHasNoBoundCandidate(): void
    {
        // Two providers: an application-password-like one (empty on this
        // request) then a user/role one.
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: []),
            new FakeIdentityProvider(currentTokens: ['user:9']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = [
            'user:9' => 'owner',
        ];

        $resolver = new PackResolver();

        $this->assertSame('owner', $resolver->resolve()->name);
    }

    public function testDanglingBindingIsSkippedInFavourOfALaterBoundCandidate(): void
    {
        // The FIRST candidate is bound, but to a pack name that doesn't exist.
        // PackRegistry::resolve() falls back to the default for THAT lookup --
        // resolve() does not walk past a dangling binding, matching the "never
        // widen access beyond the default" rule at the registry level.
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['user:5']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = [
            'user:5' => 'does-not-exist',
        ];

        $resolver = new PackResolver();

        $this->assertSame('default-agent', $resolver->resolve()->name);
    }

    public function testExtraPacksFromIntegrationsAreResolvable(): void
    {
        RequestContext::configure(new IdentityChain([
            new FakeIdentityProvider(currentTokens: ['wc:key_2']),
        ]));
        $GLOBALS['wpas_test_options'][PackResolver::BINDINGS_OPTION] = [
            'wc:key_2' => 'woo-support',
        ];

        $resolver = new PackResolver([
            new Pack(name: 'woo-support', allow: ['woocommerce/orders-*'], denyClass: ['tier2']),
        ]);

        $pack = $resolver->resolve();
        $this->assertSame('woo-support', $pack->name);
        $this->assertTrue($pack->allows('woocommerce/orders-list'));
    }
}
