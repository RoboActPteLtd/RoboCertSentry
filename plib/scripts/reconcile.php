<?php

declare(strict_types=1);

/**
 * Reconciliation job: re-read the current certificate inventory and record any
 * issuances the live event path may have missed (for example if Plesk was
 * restarted mid-issuance). Idempotent, so it can run on a frequent schedule.
 *
 * NEEDS LIVE BOX: depends on the CertificateInventory adapter being able to read
 * the cert store on this server.
 */

Modules_Robocertsentry_Autoloader::register();

$summary = Modules_Robocertsentry_Container::certificateSync()->reconcile();

pm_Log::info(sprintf(
    'RoboCertSentry reconcile: %d recorded, %d already known',
    $summary->recordedCount(),
    $summary->skippedCount()
));
