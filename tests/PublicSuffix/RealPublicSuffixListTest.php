<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\PublicSuffix;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\PublicSuffix\DomainName;
use RoboAct\CertSentry\PublicSuffix\Exception\DomainIsPublicSuffixException;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\PublicSuffix\SuffixSection;

/**
 * Exercises the resolver against the real bundled Public Suffix List so the
 * algorithm is verified on the data it will actually use in production, not
 * just on a hand-built fixture.
 */
final class RealPublicSuffixListTest extends TestCase
{
    private static RegisteredDomainResolver $withPrivate;
    private static RegisteredDomainResolver $icannOnly;

    public static function setUpBeforeClass(): void
    {
        $list = PublicSuffixList::fromFile(dirname(__DIR__, 2) . '/data/public_suffix_list.dat');
        self::$withPrivate = new RegisteredDomainResolver($list, true);
        self::$icannOnly = new RegisteredDomainResolver($list, false);
    }

    private function registrable(string $host): string
    {
        return self::$withPrivate->resolve(DomainName::fromString($host))->registrableDomain();
    }

    public function testSiblingSubdomainsShareABucket(): void
    {
        $this->assertSame('example.com', $this->registrable('a.example.com'));
        $this->assertSame('example.com', $this->registrable('b.example.com'));
        $this->assertSame('example.com', $this->registrable('deep.nested.example.com'));
    }

    public function testMultiLevelIcannSuffix(): void
    {
        $result = self::$withPrivate->resolve(DomainName::fromString('www.bbc.co.uk'));

        $this->assertSame('co.uk', $result->suffix()->value());
        $this->assertSame('bbc.co.uk', $result->registrableDomain());
    }

    public function testPrivateSuffixGivesEachTenantItsOwnBucket(): void
    {
        $result = self::$withPrivate->resolve(DomainName::fromString('my-app.github.io'));

        $this->assertSame('github.io', $result->suffix()->value());
        $this->assertSame(SuffixSection::PRIVATE, $result->suffix()->section());
        $this->assertSame('my-app.github.io', $result->registrableDomain());
    }

    public function testDisablingPrivateSectionCollapsesTenantsBackToTheHost(): void
    {
        $result = self::$icannOnly->resolve(DomainName::fromString('my-app.github.io'));

        $this->assertSame('io', $result->suffix()->value());
        $this->assertSame('github.io', $result->registrableDomain());
    }

    public function testInternationalisedTopLevelDomain(): void
    {
        // "пример.рф" -> the .рф TLD is "xn--p1ai" in Punycode.
        $result = self::$withPrivate->resolve(DomainName::fromString('пример.рф'));

        $this->assertSame('xn--p1ai', $result->suffix()->value());
        $this->assertSame('xn--e1afmkfd.xn--p1ai', $result->registrableDomain());
    }

    public function testUnknownTopLevelDomainUsesImplicitRule(): void
    {
        $result = self::$withPrivate->resolve(DomainName::fromString('shop.thistlddoesnotexist'));

        $this->assertFalse($result->suffix()->isKnown());
        $this->assertSame('shop.thistlddoesnotexist', $result->registrableDomain());
    }

    public function testApexOfAPublicSuffixIsRejected(): void
    {
        $this->expectException(DomainIsPublicSuffixException::class);

        self::$withPrivate->resolve(DomainName::fromString('co.uk'));
    }
}
