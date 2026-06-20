<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Ingest\CertificateInventory;
use RoboAct\CertSentry\Ingest\CertificateObservationRecorder;
use RoboAct\CertSentry\Ingest\CertificateSync;
use RoboAct\CertSentry\Ingest\ObservedCertificate;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;

final class CertificateSyncTest extends TestCase
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

    /**
     * @param list<ObservedCertificate> $certificates
     */
    private function inventoryOf(array $certificates): CertificateInventory
    {
        return new class ($certificates) implements CertificateInventory {
            /** @param list<ObservedCertificate> $certificates */
            public function __construct(private readonly array $certificates)
            {
            }

            public function observedCertificates(): array
            {
                return $this->certificates;
            }
        };
    }

    public function testRecordsEveryCertificateTheInventoryReports(): void
    {
        $sync = new CertificateSync($this->inventoryOf([
            new ObservedCertificate($this->at('2026-06-19 12:00:00'), ['a.example.com'], 'acct-1'),
            new ObservedCertificate($this->at('2026-06-19 12:05:00'), ['b.example.com'], 'acct-1'),
        ]), $this->recorder);

        $summary = $sync->reconcile();

        $this->assertSame(2, $summary->recordedCount());
        $this->assertSame(0, $summary->skippedCount());
    }

    public function testReReconcilingSkipsCertificatesAlreadyInTheLedger(): void
    {
        $inventory = $this->inventoryOf([
            new ObservedCertificate($this->at('2026-06-19 12:00:00'), ['a.example.com'], 'acct-1'),
        ]);

        (new CertificateSync($inventory, $this->recorder))->reconcile();
        $summary = (new CertificateSync($inventory, $this->recorder))->reconcile();

        $this->assertSame(0, $summary->recordedCount());
        $this->assertSame(1, $summary->skippedCount());
        $this->assertCount(
            1,
            $this->history->certificatesForIdentifierSet('a.example.com', $this->at('2026-05-01 00:00:00'))
        );
    }
}
