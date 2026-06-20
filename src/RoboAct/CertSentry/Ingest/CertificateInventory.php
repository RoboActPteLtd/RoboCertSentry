<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest;

/**
 * The certificates Plesk currently holds, in source-agnostic form.
 *
 * Plesk exposes no "certificate issued" event, so the live path cannot subscribe
 * to issuances directly. Instead this port lets the Plesk layer report the full
 * current cert inventory (read from the cert store or CLI) whenever a related
 * event fires, and the reconciler diffs that against the ledger. Expressing it as
 * a port keeps every Plesk detail out of the reconciler and makes the live path
 * testable with a plain fake.
 */
interface CertificateInventory
{
    /**
     * @return list<ObservedCertificate>
     */
    public function observedCertificates(): array;
}
