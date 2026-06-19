<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\PublicSuffix;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\PublicSuffix\DomainName;
use RoboAct\CertSentry\PublicSuffix\Exception\InvalidDomainNameException;

final class DomainNameTest extends TestCase
{
    public function testLowercasesInput(): void
    {
        $domain = DomainName::fromString('EXAMPLE.COM');

        $this->assertSame('example.com', $domain->ascii());
    }

    public function testStripsTrailingRootDot(): void
    {
        $domain = DomainName::fromString('example.com.');

        $this->assertSame('example.com', $domain->ascii());
    }

    public function testTrimsSurroundingWhitespace(): void
    {
        $domain = DomainName::fromString('  example.com  ');

        $this->assertSame('example.com', $domain->ascii());
    }

    public function testConvertsUnicodeToPunycode(): void
    {
        $domain = DomainName::fromString('münchen.de');

        $this->assertSame('xn--mnchen-3ya.de', $domain->ascii());
    }

    public function testLeavesAlreadyEncodedPunycodeUntouched(): void
    {
        $domain = DomainName::fromString('xn--mnchen-3ya.de');

        $this->assertSame('xn--mnchen-3ya.de', $domain->ascii());
    }

    public function testExposesLabelsInOrder(): void
    {
        $domain = DomainName::fromString('a.example.com');

        $this->assertSame(['a', 'example', 'com'], $domain->labels());
    }

    public function testStringableReturnsAsciiForm(): void
    {
        $domain = DomainName::fromString('Example.Com');

        $this->assertSame('example.com', (string) $domain);
    }

    public function testRejectsEmptyInput(): void
    {
        $this->expectException(InvalidDomainNameException::class);

        DomainName::fromString('   ');
    }

    public function testRejectsEmptyLabel(): void
    {
        $this->expectException(InvalidDomainNameException::class);

        DomainName::fromString('foo..com');
    }
}
