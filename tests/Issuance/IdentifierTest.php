<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Issuance;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Issuance\Identifier;
use RoboAct\CertSentry\PublicSuffix\Exception\InvalidDomainNameException;

final class IdentifierTest extends TestCase
{
    public function testNormalisesAPlainHostname(): void
    {
        $identifier = Identifier::fromString('Example.COM');

        $this->assertSame('example.com', $identifier->value());
        $this->assertFalse($identifier->isWildcard());
    }

    public function testRecognisesAWildcard(): void
    {
        $identifier = Identifier::fromString('*.example.com');

        $this->assertTrue($identifier->isWildcard());
        $this->assertSame('*.example.com', $identifier->value());
    }

    public function testExposesTheBaseDomainForRegisteredDomainResolution(): void
    {
        $identifier = Identifier::fromString('*.example.com');

        // The wildcard resolves against the same host as its base, so the base
        // is what the registered-domain resolver consumes.
        $this->assertSame('example.com', $identifier->baseDomain()->ascii());
    }

    public function testNormalisesInternationalisedWildcard(): void
    {
        $identifier = Identifier::fromString('*.münchen.de');

        $this->assertSame('*.xn--mnchen-3ya.de', $identifier->value());
    }

    public function testRejectsABareWildcard(): void
    {
        $this->expectException(InvalidDomainNameException::class);

        Identifier::fromString('*');
    }

    public function testRejectsAWildcardThatIsNotTheLeftmostLabel(): void
    {
        $this->expectException(InvalidDomainNameException::class);

        Identifier::fromString('foo.*.example.com');
    }
}
