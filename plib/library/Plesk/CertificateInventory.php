<?php

declare(strict_types=1);

use RoboAct\CertSentry\Ingest\CertificateInventory;
use RoboAct\CertSentry\Ingest\ObservedCertificate;

/**
 * Reports the certificates Plesk currently holds, as ObservedCertificate values.
 *
 * This is the live path's only window into Plesk. Because Plesk fires no issuance
 * event, the reconciler calls this to learn the present state and records whatever
 * is new. Each certificate becomes an observation timestamped at the cert's
 * notBefore (the closest proxy for "when it was issued") carrying its full
 * identifier set, so the core can bucket and de-duplicate it exactly as it would a
 * log-sourced one.
 *
 * NEEDS LIVE BOX: the means of locating and reading each domain's current Let's
 * Encrypt certificate is the inferred part. The archive path and per-domain file
 * names must be confirmed; the SAN and notBefore extraction via openssl is sound
 * once the .pem is in hand. Verify against /usr/local/psa/var/modules/sslit.
 */
final class Modules_Robocertsentry_Plesk_CertificateInventory implements CertificateInventory
{
    private const DEFAULT_ARCHIVE = '/usr/local/psa/var/modules/sslit/etc/archive';

    public function observedCertificates(): array
    {
        $observations = [];
        foreach (pm_Domain::getAllDomains() as $domain) {
            $pem = $this->readCurrentCertificate($domain->getName());
            if ($pem === null) {
                continue;
            }

            $observation = $this->toObservation($pem);
            if ($observation !== null) {
                $observations[] = $observation;
            }
        }

        return $observations;
    }

    private function toObservation(string $pem): ?ObservedCertificate
    {
        $parsed = openssl_x509_parse($pem);
        if ($parsed === false) {
            return null;
        }

        $identifiers = $this->identifiersFrom($parsed);
        if ($identifiers === []) {
            return null;
        }

        $occurredAt = (new \DateTimeImmutable('@' . $parsed['validFrom_time_t']))
            ->setTimezone(new \DateTimeZone('UTC'));

        // Renewal classification is left to the core, which knows the prior ledger.
        return new ObservedCertificate($occurredAt, $identifiers, Modules_Robocertsentry_Container::accountId());
    }

    /**
     * The certificate's identifiers, taken from the subjectAltName extension and
     * falling back to the common name, normalised to bare host strings.
     *
     * @param array<string, mixed> $parsed
     * @return list<string>
     */
    private function identifiersFrom(array $parsed): array
    {
        $names = [];

        $san = $parsed['extensions']['subjectAltName'] ?? '';
        foreach (explode(',', (string) $san) as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $names[substr($entry, 4)] = true;
            }
        }

        if ($names === [] && isset($parsed['subject']['CN'])) {
            $names[(string) $parsed['subject']['CN']] = true;
        }

        return array_keys($names);
    }

    /**
     * NEEDS LIVE BOX: confirm the on-disk location and filename of a domain's
     * active Let's Encrypt certificate. Returns null when none is found so a
     * domain without an LE certificate simply contributes nothing.
     */
    private function readCurrentCertificate(string $domainName): ?string
    {
        $base = (string) pm_Settings::get('cert_archive_path', self::DEFAULT_ARCHIVE);
        $candidate = $base . '/' . $domainName . '/cert.pem';

        if (!is_file($candidate)) {
            return null;
        }

        $contents = file_get_contents($candidate);

        return $contents === false ? null : $contents;
    }
}
