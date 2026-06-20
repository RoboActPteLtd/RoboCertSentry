<?php

declare(strict_types=1);

use RoboAct\CertSentry\Ingest\CertificateInventory;
use RoboAct\CertSentry\Ingest\LetsEncryptIssuer;
use RoboAct\CertSentry\Ingest\ObservedCertificate;

/**
 * Reports the certificates Plesk currently holds, as ObservedCertificate values.
 *
 * This is the live path's only window into Plesk. Because Plesk fires no issuance
 * event, the reconciler calls this to learn the present state and records whatever
 * is new. Certificates are read through the documented pm_Domain accessors
 * (verified on Plesk 18.0.78: getHostingCertificate/getMailCertificate/
 * getWebmailCertificate return a Plesk\SDK\Certificate whose getCert() yields the
 * PEM), so there is no dependence on the on-disk cert-store layout. Each cert
 * becomes an observation timestamped at its notBefore, the closest proxy for when
 * it was issued, carrying its full identifier set so the core can bucket and
 * de-duplicate it exactly as it would a log-sourced one.
 */
final class Modules_Robocertsentry_Plesk_CertificateInventory implements CertificateInventory
{
    public function observedCertificates(): array
    {
        $observations = [];
        foreach (pm_Domain::getAllDomains() as $domain) {
            foreach ($this->certificatesOf($domain) as $certificate) {
                $observation = $this->toObservation($certificate);
                if ($observation !== null) {
                    $observations[] = $observation;
                }
            }
        }

        return $observations;
    }

    /**
     * Every certificate bound to one domain across its hosting, mail, and webmail
     * bindings. Each accessor returns null when no certificate is assigned.
     *
     * @return list<\Plesk\SDK\Certificate>
     */
    private function certificatesOf(pm_Domain $domain): array
    {
        $certificates = [];
        foreach (['getHostingCertificate', 'getMailCertificate', 'getWebmailCertificate'] as $accessor) {
            try {
                $certificate = $domain->{$accessor}();
            } catch (Throwable) {
                // A binding that cannot be read is simply skipped; one unreadable
                // domain must not abort the whole reconcile.
                continue;
            }
            if ($certificate !== null) {
                $certificates[] = $certificate;
            }
        }

        return $certificates;
    }

    private function toObservation(\Plesk\SDK\Certificate $certificate): ?ObservedCertificate
    {
        $parsed = openssl_x509_parse($certificate->getCert());
        if ($parsed === false) {
            return null;
        }

        // Only Let's Encrypt issuances consume Let's Encrypt limits. A server's
        // self-signed default and any other CA's certificate must not pollute the
        // ledger, so non-LE certificates are ignored here. Verified necessary on a
        // live box, where getHostingCertificate also surfaces the panel default.
        if (!$this->isLetsEncrypt($parsed)) {
            return null;
        }

        $identifiers = $this->identifiersFrom($parsed);
        if ($identifiers === [] || !isset($parsed['validFrom_time_t'])) {
            return null;
        }

        $occurredAt = (new \DateTimeImmutable('@' . $parsed['validFrom_time_t']))
            ->setTimezone(new \DateTimeZone('UTC'));

        // Renewal classification is left to the core, which knows the prior ledger.
        return new ObservedCertificate($occurredAt, $identifiers, Modules_Robocertsentry_Container::accountId());
    }

    /**
     * Whether the certificate was issued by Let's Encrypt, judged from the issuer
     * organisation and common name. Delegates to the tested core matcher, which is
     * robust to how the parser renders the issuer (a live box returned the issuer
     * without its apostrophe).
     *
     * @param array<string, mixed> $parsed
     */
    private function isLetsEncrypt(array $parsed): bool
    {
        $issuer = $parsed['issuer'] ?? [];

        return LetsEncryptIssuer::matches((string) ($issuer['O'] ?? ''), (string) ($issuer['CN'] ?? ''));
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
}
