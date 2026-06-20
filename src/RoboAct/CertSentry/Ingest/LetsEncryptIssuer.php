<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest;

/**
 * Decides whether a certificate's issuer is Let's Encrypt.
 *
 * Only Let's Encrypt issuances consume Let's Encrypt rate limits, so the inventory
 * uses this to keep a server's self-signed default and any other CA's certificate
 * out of the ledger. The match is on a normalised form (lowercased, stripped of
 * non-alphanumerics) because certificate parsers do not render the issuer
 * consistently: a live box showed openssl_x509_parse returning "Lets Encrypt" with
 * the apostrophe removed, which an exact match would have missed.
 */
final class LetsEncryptIssuer
{
    public static function matches(string $organisation, string $commonName): bool
    {
        $normalised = preg_replace('/[^a-z0-9]/', '', strtolower($organisation . ' ' . $commonName));

        return str_contains($normalised, 'letsencrypt') || str_contains($normalised, 'isrg');
    }
}
