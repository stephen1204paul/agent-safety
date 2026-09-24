<?php

declare(strict_types=1);

namespace Specflux\AgentSafety\Plugin\Tests\Support;

use PHPUnit\Framework\TestCase;
use Specflux\AgentSafety\Plugin\Support\SiteBinding;

/**
 * Pure normalisation (AS-7 §3.4 item 2): scheme is ignored (http => https is
 * not a site move), an explicit default port is equivalent to no port, a
 * different path IS a different site, and the host is lowercased and
 * IDN-normalised.
 */
final class SiteBindingTest extends TestCase
{
    public function testHttpToHttpsIsNotAMismatch(): void
    {
        $this->assertSame(
            SiteBinding::normalize('http://example.com'),
            SiteBinding::normalize('https://example.com'),
        );
    }

    public function testDifferentPathsAreDifferentSites(): void
    {
        $this->assertNotSame(
            SiteBinding::normalize('https://example.com/shop'),
            SiteBinding::normalize('https://example.com/staging'),
        );
    }

    public function testHostIsCaseInsensitive(): void
    {
        $this->assertSame(
            SiteBinding::normalize('https://Example.COM'),
            SiteBinding::normalize('https://example.com'),
        );
    }

    public function testTrailingSlashIsIgnored(): void
    {
        $this->assertSame(
            SiteBinding::normalize('https://example.com/shop/'),
            SiteBinding::normalize('https://example.com/shop'),
        );
    }

    public function testRootPathAndNoPathAreTheSame(): void
    {
        $this->assertSame(
            SiteBinding::normalize('https://example.com/'),
            SiteBinding::normalize('https://example.com'),
        );
    }

    public function testExplicitDefaultHttpsPortIsEquivalentToNoPort(): void
    {
        $this->assertSame(
            SiteBinding::normalize('https://example.com:443'),
            SiteBinding::normalize('https://example.com'),
        );
    }

    public function testExplicitDefaultHttpPortIsEquivalentToNoPort(): void
    {
        $this->assertSame(
            SiteBinding::normalize('http://example.com:80'),
            SiteBinding::normalize('http://example.com'),
        );
    }

    public function testANonDefaultPortIsADifferentSite(): void
    {
        $this->assertNotSame(
            SiteBinding::normalize('https://example.com:8443'),
            SiteBinding::normalize('https://example.com'),
        );
    }

    public function testACrossSchemeNonDefaultPortStillMatters(): void
    {
        // http on 443 is NOT the https default port, so it is kept.
        $this->assertNotSame(
            SiteBinding::normalize('http://example.com:443'),
            SiteBinding::normalize('http://example.com'),
        );
    }

    public function testDifferentHostsAreDifferentSites(): void
    {
        $this->assertNotSame(
            SiteBinding::normalize('https://example.com'),
            SiteBinding::normalize('https://example.org'),
        );
    }

    public function testUnparseableInputDegradesToItsOwnLowercasedString(): void
    {
        $this->assertSame('http:///a b', SiteBinding::normalize('HTTP:///A B'));
    }

    public function testCombinedPortAndPathAreBothHonoured(): void
    {
        $this->assertSame(
            'example.com:8080/shop',
            SiteBinding::normalize('https://example.com:8080/shop/'),
        );
    }
}
