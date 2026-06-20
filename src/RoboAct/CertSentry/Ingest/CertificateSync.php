<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest;

/**
 * The live ingestion path: reconcile the current cert inventory into the ledger.
 *
 * Because Plesk fires no issuance event, the Plesk layer instead calls this when
 * a cert-adjacent event occurs (a domain, hosting, or DNS change). The reconciler
 * asks the inventory for everything currently held and offers each to the
 * recorder, which records the genuinely new ones and quietly drops the rest. That
 * makes the pass safe to run as often as events arrive: re-seeing a certificate
 * is expected, not an error.
 */
final class CertificateSync
{
    public function __construct(
        private readonly CertificateInventory $inventory,
        private readonly CertificateObservationRecorder $recorder,
    ) {
    }

    public function reconcile(): SyncSummary
    {
        $recorded = 0;
        $skipped = 0;

        foreach ($this->inventory->observedCertificates() as $certificate) {
            $outcome = $this->recorder->record($certificate);
            if ($outcome === RecordOutcome::RECORDED) {
                $recorded++;
            } else {
                $skipped++;
            }
        }

        return new SyncSummary($recorded, $skipped);
    }
}
