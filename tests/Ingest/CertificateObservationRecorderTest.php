<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Ingest\CertificateObservationRecorder;
use RoboAct\CertSentry\Ingest\ObservedCertificate;
use RoboAct\CertSentry\Ingest\RecordOutcome;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;

final class CertificateObservationRecorderTest extends TestCase
{
    private const PSL = "// ===BEGIN ICANN DOMAINS===\ncom\nco.uk\nuk\norg\n// ===END ICANN DOMAINS===";

    private InMemoryIssuanceHistory $history;
    private CertificateObservationRecorder $recorder;

    protected function setUp(): void
    {
        $this->history = new InMemoryIssuanceHistory();
        $this->recorder = new CertificateObservationRecorder(
            new RegisteredDomainResolver(PublicSuffixList::fromString(self::PSL)),
            $this->history,
            $this->history
        );
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
    }

    public function testRecordsANewObservationAsAnIssuance(): void
    {
        $outcome = $this->recorder->record(new ObservedCertificate(
            $this->at('2026-06-19 12:00:00'),
            ['a.example.com', 'b.example.com'],
            'acct-1'
        ));

        $this->assertSame(RecordOutcome::RECORDED, $outcome);

        $stored = $this->history->ordersForAccount('acct-1', $this->at('2026-06-01 00:00:00'));
        $this->assertCount(1, $stored);
        $this->assertSame(['example.com'], $stored[0]->registeredDomains());
        $this->assertSame('a.example.com,b.example.com', $stored[0]->identifiers()->canonicalKey());
    }

    public function testDerivesRenewalFromHistoryWhenNotStated(): void
    {
        $first = $this->recorder->record(new ObservedCertificate(
            $this->at('2026-06-01 09:00:00'),
            ['shop.example.com'],
            'acct-1'
        ));
        $second = $this->recorder->record(new ObservedCertificate(
            $this->at('2026-06-15 09:00:00'),
            ['shop.example.com'],
            'acct-1'
        ));

        $this->assertSame(RecordOutcome::RECORDED, $first);
        $this->assertSame(RecordOutcome::RECORDED, $second);

        $records = $this->history->certificatesForIdentifierSet('shop.example.com', $this->at('2026-05-01 00:00:00'));
        $this->assertFalse($records[0]->isRenewal(), 'first issuance of a set is new');
        $this->assertTrue($records[1]->isRenewal(), 'a later issuance of the same set is a renewal');
    }

    public function testHonoursAnExplicitRenewalFlag(): void
    {
        $this->recorder->record(new ObservedCertificate(
            $this->at('2026-06-19 12:00:00'),
            ['api.example.com'],
            'acct-1',
            true
        ));

        $records = $this->history->certificatesForIdentifierSet('api.example.com', $this->at('2026-05-01 00:00:00'));
        $this->assertTrue($records[0]->isRenewal(), 'a stated renewal is recorded as a renewal even with no prior record');
    }

    public function testSkipsADuplicateOfTheSameEvent(): void
    {
        $observation = new ObservedCertificate(
            $this->at('2026-06-19 12:00:00'),
            ['a.example.com'],
            'acct-1'
        );

        $first = $this->recorder->record($observation);
        $second = $this->recorder->record($observation);

        $this->assertSame(RecordOutcome::RECORDED, $first);
        $this->assertSame(RecordOutcome::DUPLICATE_SKIPPED, $second);
        $this->assertCount(
            1,
            $this->history->certificatesForIdentifierSet('a.example.com', $this->at('2026-05-01 00:00:00'))
        );
    }

    public function testTreatsTheSameSetFarApartAsDistinctIssuances(): void
    {
        $this->recorder->record(new ObservedCertificate($this->at('2026-06-01 12:00:00'), ['a.example.com'], 'acct-1'));
        $second = $this->recorder->record(new ObservedCertificate($this->at('2026-06-08 12:00:00'), ['a.example.com'], 'acct-1'));

        $this->assertSame(RecordOutcome::RECORDED, $second);
        $this->assertCount(
            2,
            $this->history->certificatesForIdentifierSet('a.example.com', $this->at('2026-05-01 00:00:00'))
        );
    }

    public function testCollapsesIdentifiersSharingARegisteredDomainIntoOneBucket(): void
    {
        $this->recorder->record(new ObservedCertificate(
            $this->at('2026-06-19 12:00:00'),
            ['a.example.com', 'b.example.com', 'other.co.uk'],
            'acct-1'
        ));

        $stored = $this->history->ordersForAccount('acct-1', $this->at('2026-05-01 00:00:00'));
        $this->assertEqualsCanonicalizing(['example.com', 'other.co.uk'], $stored[0]->registeredDomains());
    }
}
