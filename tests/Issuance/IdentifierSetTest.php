<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Issuance;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Issuance\Exception\InvalidIdentifierSetException;
use RoboAct\CertSentry\Issuance\IdentifierSet;

final class IdentifierSetTest extends TestCase
{
    public function testCanonicalKeyIsIndependentOfOrderAndCase(): void
    {
        $a = IdentifierSet::fromStrings(['b.com', 'A.com']);
        $b = IdentifierSet::fromStrings(['a.com', 'B.COM']);

        $this->assertSame($a->canonicalKey(), $b->canonicalKey());
    }

    public function testCanonicalKeyIsSortedAndJoined(): void
    {
        $set = IdentifierSet::fromStrings(['b.com', 'a.com']);

        $this->assertSame('a.com,b.com', $set->canonicalKey());
    }

    public function testCollapsesDuplicates(): void
    {
        $set = IdentifierSet::fromStrings(['a.com', 'A.com', 'a.com']);

        $this->assertCount(1, $set->identifiers());
    }

    public function testKeepsWildcardDistinctFromItsBase(): void
    {
        $set = IdentifierSet::fromStrings(['example.com', '*.example.com']);

        $this->assertCount(2, $set->identifiers());
        $this->assertSame('*.example.com,example.com', $set->canonicalKey());
    }

    public function testEqualsComparesByExactSet(): void
    {
        $a = IdentifierSet::fromStrings(['a.com', 'b.com']);
        $b = IdentifierSet::fromStrings(['b.com', 'a.com']);
        $c = IdentifierSet::fromStrings(['a.com']);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }

    public function testRejectsAnEmptySet(): void
    {
        $this->expectException(InvalidIdentifierSetException::class);

        IdentifierSet::fromStrings([]);
    }
}
