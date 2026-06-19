<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Storage;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\ValidationFailureRecord;
use RoboAct\CertSentry\Storage\SqliteIssuanceHistory;

final class SqliteIssuanceHistoryTest extends TestCase
{
    private SqliteIssuanceHistory $store;

    protected function setUp(): void
    {
        $this->store = SqliteIssuanceHistory::inMemory();
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    private function record(string $time, array $names, string $account, array $registeredDomains, bool $isRenewal = false): CertificateIssuanceRecord
    {
        return new CertificateIssuanceRecord(
            $this->at($time),
            IdentifierSet::fromStrings($names),
            $account,
            $registeredDomains,
            $isRenewal
        );
    }

    public function testRoundTripsAnIssuanceAndReconstructsItsFields(): void
    {
        $this->store->recordIssuance($this->record('2026-06-19 10:00:00', ['a.example.com'], 'acct-1', ['example.com'], true));

        $found = $this->store->certificatesForRegisteredDomain('example.com', $this->at('2026-06-12 10:00:00'));

        $this->assertCount(1, $found);
        $this->assertSame('a.example.com', $found[0]->identifiers()->canonicalKey());
        $this->assertSame(['example.com'], $found[0]->registeredDomains());
        $this->assertTrue($found[0]->isRenewal());
        $this->assertEquals($this->at('2026-06-19 10:00:00'), $found[0]->occurredAt());
    }

    public function testExcludesIssuancesOutsideTheWindow(): void
    {
        $this->store->recordIssuance($this->record('2026-06-01 10:00:00', ['a.example.com'], 'acct-1', ['example.com']));

        $this->assertCount(0, $this->store->certificatesForRegisteredDomain('example.com', $this->at('2026-06-12 10:00:00')));
    }

    public function testFindsByExactIdentifierSet(): void
    {
        $this->store->recordIssuance($this->record('2026-06-19 10:00:00', ['b.com', 'a.com'], 'acct-1', ['a.com', 'b.com']));

        $key = IdentifierSet::fromStrings(['a.com', 'b.com'])->canonicalKey();
        $this->assertCount(1, $this->store->certificatesForIdentifierSet($key, $this->at('2026-06-12 10:00:00')));
    }

    public function testFindsByAccount(): void
    {
        $this->store->recordIssuance($this->record('2026-06-19 10:00:00', ['a.example.com'], 'acct-1', ['example.com']));
        $this->store->recordIssuance($this->record('2026-06-19 10:00:00', ['b.example.com'], 'acct-2', ['example.com']));

        $this->assertCount(1, $this->store->ordersForAccount('acct-1', $this->at('2026-06-19 09:00:00')));
    }

    public function testFindsIssuanceUnderEachRegisteredDomainItTouches(): void
    {
        $this->store->recordIssuance($this->record('2026-06-19 10:00:00', ['a.example.com', 'b.other.org'], 'acct-1', ['example.com', 'other.org']));

        $this->assertCount(1, $this->store->certificatesForRegisteredDomain('example.com', $this->at('2026-06-12 10:00:00')));
        $this->assertCount(1, $this->store->certificatesForRegisteredDomain('other.org', $this->at('2026-06-12 10:00:00')));
    }

    public function testFiltersValidationFailures(): void
    {
        $this->store->recordValidationFailure(new ValidationFailureRecord($this->at('2026-06-19 11:50:00'), 'a.example.com', 'acct-1'));
        $this->store->recordValidationFailure(new ValidationFailureRecord($this->at('2026-06-19 10:00:00'), 'a.example.com', 'acct-1'));

        $this->assertCount(1, $this->store->validationFailures('a.example.com', 'acct-1', $this->at('2026-06-19 11:00:00')));
    }

    public function testKnowsWhetherAnIdentifierSetWasEverIssued(): void
    {
        $this->store->recordIssuance($this->record('2026-01-01 10:00:00', ['a.com'], 'acct-1', ['a.com']));

        $this->assertTrue($this->store->hasIssuedIdentifierSet(IdentifierSet::fromStrings(['a.com'])->canonicalKey()));
        $this->assertFalse($this->store->hasIssuedIdentifierSet('z.com'));
    }
}
