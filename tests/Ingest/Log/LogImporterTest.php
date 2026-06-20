<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Ingest\Log;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Ingest\CertificateObservationRecorder;
use RoboAct\CertSentry\Ingest\Log\CertOperationLogParser;
use RoboAct\CertSentry\Ingest\Log\LogImporter;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;

final class LogImporterTest extends TestCase
{
    private const PSL = "// ===BEGIN ICANN DOMAINS===\ncom\nco.uk\nuk\norg\n// ===END ICANN DOMAINS===";

    private InMemoryIssuanceHistory $history;
    private LogImporter $importer;

    protected function setUp(): void
    {
        $this->history = new InMemoryIssuanceHistory();
        $recorder = new CertificateObservationRecorder(
            new RegisteredDomainResolver(PublicSuffixList::fromString(self::PSL)),
            $this->history,
            $this->history
        );
        $this->importer = new LogImporter(new CertOperationLogParser(new \DateTimeZone('UTC')), $recorder);
    }

    private function since(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));
    }

    public function testImportsEveryParsedOperationUnderTheGivenAccount(): void
    {
        $log = implode("\n", [
            '2026-06-01 09:00:03 INFO [letsencrypt] Certificate issued for a.example.com',
            '2026-06-08 09:00:03 INFO [letsencrypt] Certificate renewed for a.example.com',
        ]);

        $summary = $this->importer->import($log, 'acct-le');

        $this->assertSame(2, $summary->parsedCount());
        $this->assertSame(2, $summary->recordedCount());
        $this->assertSame(0, $summary->skippedCount());

        $records = $this->history->ordersForAccount('acct-le', $this->since());
        $this->assertCount(2, $records);
        $this->assertFalse($records[0]->isRenewal());
        $this->assertTrue($records[1]->isRenewal());
    }

    public function testIsIdempotentAcrossRepeatedImports(): void
    {
        $log = '2026-06-01 09:00:03 INFO [letsencrypt] Certificate issued for a.example.com';

        $this->importer->import($log, 'acct-le');
        $summary = $this->importer->import($log, 'acct-le');

        $this->assertSame(1, $summary->parsedCount());
        $this->assertSame(0, $summary->recordedCount());
        $this->assertSame(1, $summary->skippedCount());
        $this->assertCount(1, $this->history->ordersForAccount('acct-le', $this->since()));
    }
}
