<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\PublicSuffix;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\PublicSuffix\DomainName;
use RoboAct\CertSentry\PublicSuffix\Exception\DomainIsPublicSuffixException;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\PublicSuffix\SuffixSection;

final class RegisteredDomainResolverTest extends TestCase
{
    private const SAMPLE = <<<'PSL'
        // ===BEGIN ICANN DOMAINS===
        com
        co.uk
        uk
        *.ck
        !www.ck
        // ===END ICANN DOMAINS===
        // ===BEGIN PRIVATE DOMAINS===
        blogspot.com
        // ===END PRIVATE DOMAINS===
        PSL;

    private function resolver(bool $includePrivate = true): RegisteredDomainResolver
    {
        return new RegisteredDomainResolver(
            PublicSuffixList::fromString(self::SAMPLE),
            $includePrivate
        );
    }

    private function registrableFor(string $host, bool $includePrivate = true): string
    {
        return $this->resolver($includePrivate)
            ->resolve(DomainName::fromString($host))
            ->registrableDomain();
    }

    public function testGroupsSiblingSubdomainsUnderOneRegisteredDomain(): void
    {
        $this->assertSame('example.com', $this->registrableFor('a.example.com'));
        $this->assertSame('example.com', $this->registrableFor('b.example.com'));
    }

    public function testResolvesMultiLevelPublicSuffix(): void
    {
        $result = $this->resolver()->resolve(DomainName::fromString('foo.bar.co.uk'));

        $this->assertSame('bar.co.uk', $result->registrableDomain());
        $this->assertSame('co.uk', $result->suffix()->value());
    }

    public function testPrefersTheLongestMatchingSuffix(): void
    {
        // "co.uk" must win over the shorter "uk" rule.
        $result = $this->resolver()->resolve(DomainName::fromString('shop.co.uk'));

        $this->assertSame('co.uk', $result->suffix()->value());
        $this->assertSame('shop.co.uk', $result->registrableDomain());
    }

    public function testAppliesWildcardRule(): void
    {
        // "*.ck" makes "b.ck" a public suffix, so the registrable domain needs
        // one more label to its left.
        $this->assertSame('a.b.ck', $this->registrableFor('a.b.ck'));
    }

    public function testExceptionRuleNarrowsTheSuffix(): void
    {
        $result = $this->resolver()->resolve(DomainName::fromString('www.ck'));

        $this->assertSame('ck', $result->suffix()->value());
        $this->assertSame('www.ck', $result->registrableDomain());
    }

    public function testIncludesPrivateSuffixesByDefault(): void
    {
        $result = $this->resolver()->resolve(DomainName::fromString('tenant.blogspot.com'));

        $this->assertSame('blogspot.com', $result->suffix()->value());
        $this->assertSame(SuffixSection::PRIVATE, $result->suffix()->section());
        $this->assertSame('tenant.blogspot.com', $result->registrableDomain());
    }

    public function testCanIgnorePrivateSuffixes(): void
    {
        // With the private section disabled the tenants collapse back into the
        // single "blogspot.com" registered-domain bucket under the ICANN "com".
        $this->assertSame('blogspot.com', $this->registrableFor('tenant.blogspot.com', false));
    }

    public function testUnknownTopLevelDomainFallsBackToImplicitRule(): void
    {
        $result = $this->resolver()->resolve(DomainName::fromString('shop.example.invalidtld'));

        $this->assertSame('invalidtld', $result->suffix()->value());
        $this->assertSame(SuffixSection::UNKNOWN, $result->suffix()->section());
        $this->assertFalse($result->suffix()->isKnown());
        $this->assertSame('example.invalidtld', $result->registrableDomain());
    }

    public function testReportsIcannSectionForKnownSuffix(): void
    {
        $suffix = $this->resolver()->resolve(DomainName::fromString('a.example.com'))->suffix();

        $this->assertSame(SuffixSection::ICANN, $suffix->section());
        $this->assertTrue($suffix->isKnown());
    }

    public function testRejectsADomainThatIsItselfAPublicSuffix(): void
    {
        $this->expectException(DomainIsPublicSuffixException::class);

        $this->resolver()->resolve(DomainName::fromString('co.uk'));
    }

    public function testResolvesInternationalisedDomain(): void
    {
        $this->assertSame('xn--caf-dma.com', $this->registrableFor('café.com'));
    }
}
