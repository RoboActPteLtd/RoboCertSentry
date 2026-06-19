<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Issuance;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\Issuance\ValidationFailureRecord;

final class InMemoryIssuanceHistoryTest extends TestCase
{
    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private function issuance(string $time, array $names, string $account, array $registeredDomains, bool $isRenewal = false): CertificateIssuanceRecord
    {
        return new CertificateIssuanceRecord(
            $this->at($time),
            IdentifierSet::fromStrings($names),
            $account,
            $registeredDomains,
            $isRenewal
        );
    }

    public function testReturnsCertificatesTouchingARegisteredDomainWithinWindow(): void
    {
        $history = new InMemoryIssuanceHistory();
        $history->recordIssuance($this->issuance('2026-06-19 10:00:00', ['a.example.com'], 'acct-1', ['example.com']));
        $history->recordIssuance($this->issuance('2026-06-19 11:00:00', ['shop.other.org'], 'acct-1', ['other.org']));

        $found = $history->certificatesForRegisteredDomain('example.com', $this->at('2026-06-12 10:00:00'));

        $this->assertCount(1, $found);
    }

    public function testExcludesCertificatesOlderThanTheWindow(): void
    {
        $history = new InMemoryIssuanceHistory();
        $history->recordIssuance($this->issuance('2026-06-01 10:00:00', ['a.example.com'], 'acct-1', ['example.com']));

        $found = $history->certificatesForRegisteredDomain('example.com', $this->at('2026-06-12 10:00:00'));

        $this->assertCount(0, $found);
    }

    public function testMatchesByExactIdentifierSet(): void
    {
        $history = new InMemoryIssuanceHistory();
        $history->recordIssuance($this->issuance('2026-06-19 10:00:00', ['a.com', 'b.com'], 'acct-1', ['a.com', 'b.com']));

        $key = IdentifierSet::fromStrings(['b.com', 'a.com'])->canonicalKey();
        $found = $history->certificatesForIdentifierSet($key, $this->at('2026-06-12 10:00:00'));

        $this->assertCount(1, $found);
    }

    public function testFiltersOrdersByAccountAndWindow(): void
    {
        $history = new InMemoryIssuanceHistory();
        $history->recordIssuance($this->issuance('2026-06-19 10:00:00', ['a.example.com'], 'acct-1', ['example.com']));
        $history->recordIssuance($this->issuance('2026-06-19 10:30:00', ['b.example.com'], 'acct-2', ['example.com']));

        $found = $history->ordersForAccount('acct-1', $this->at('2026-06-19 09:00:00'));

        $this->assertCount(1, $found);
    }

    public function testFiltersValidationFailuresByIdentifierAccountAndWindow(): void
    {
        $history = new InMemoryIssuanceHistory();
        $history->recordValidationFailure(new ValidationFailureRecord($this->at('2026-06-19 11:50:00'), 'a.example.com', 'acct-1'));
        $history->recordValidationFailure(new ValidationFailureRecord($this->at('2026-06-19 10:00:00'), 'a.example.com', 'acct-1'));
        $history->recordValidationFailure(new ValidationFailureRecord($this->at('2026-06-19 11:55:00'), 'other.example.com', 'acct-1'));

        $found = $history->validationFailures('a.example.com', 'acct-1', $this->at('2026-06-19 11:00:00'));

        $this->assertCount(1, $found);
    }

    public function testKnowsWhetherAnIdentifierSetWasEverIssued(): void
    {
        $history = new InMemoryIssuanceHistory();
        $history->recordIssuance($this->issuance('2026-01-01 10:00:00', ['a.com'], 'acct-1', ['a.com']));

        $this->assertTrue($history->hasIssuedIdentifierSet(IdentifierSet::fromStrings(['a.com'])->canonicalKey()));
        $this->assertFalse($history->hasIssuedIdentifierSet(IdentifierSet::fromStrings(['z.com'])->canonicalKey()));
    }
}
