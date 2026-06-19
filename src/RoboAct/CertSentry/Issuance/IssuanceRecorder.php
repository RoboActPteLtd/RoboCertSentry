<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance;

/**
 * Write access to issuance activity.
 *
 * Recording is kept separate from reading (IssuanceHistory) so that components
 * which only predict never gain the ability to mutate the ledger, and so that
 * the Plesk log importer and the live request hook can share one narrow writing
 * contract.
 */
interface IssuanceRecorder
{
    public function recordIssuance(CertificateIssuanceRecord $record): void;

    public function recordValidationFailure(ValidationFailureRecord $record): void;
}
