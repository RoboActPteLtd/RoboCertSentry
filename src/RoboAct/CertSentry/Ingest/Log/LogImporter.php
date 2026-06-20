<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest\Log;

use RoboAct\CertSentry\Ingest\CertificateObservationRecorder;
use RoboAct\CertSentry\Ingest\ObservedCertificate;
use RoboAct\CertSentry\Ingest\RecordOutcome;

/**
 * The backfill ingestion path: parse log text and record what it reveals.
 *
 * This turns historical and reconciliation log content into ledger records by
 * pairing the parser's facts with the one thing logs omit, the issuing account,
 * which the caller knows from the server. Because every observation flows through
 * the same recorder the live path uses, re-importing an overlapping log window is
 * safe by construction: already-known operations are skipped, not duplicated.
 */
final class LogImporter
{
    public function __construct(
        private readonly CertOperationLogParser $parser,
        private readonly CertificateObservationRecorder $recorder,
    ) {
    }

    public function import(string $logContent, string $accountId): ImportSummary
    {
        $operations = $this->parser->parse($logContent);

        $recorded = 0;
        $skipped = 0;
        foreach ($operations as $operation) {
            $outcome = $this->recorder->record(new ObservedCertificate(
                $operation->occurredAt(),
                $operation->identifiers(),
                $accountId,
                $operation->isRenewal()
            ));

            if ($outcome === RecordOutcome::RECORDED) {
                $recorded++;
            } else {
                $skipped++;
            }
        }

        return new ImportSummary(count($operations), $recorded, $skipped);
    }
}
