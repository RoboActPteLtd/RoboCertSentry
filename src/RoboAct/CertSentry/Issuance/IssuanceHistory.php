<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance;

/**
 * Read access to past issuance activity, the evidence the guard reasons from.
 *
 * Every method is windowed by a "since" instant because Let's Encrypt limits are
 * sliding windows: the caller computes "now minus the limit's window" and asks
 * what falls inside. Returning the records (rather than bare counts) lets one
 * query serve both the counting limits and the wildcard advisor, which needs the
 * actual identifier sets. Implementations back this with anything from an array
 * to SQLite; the guard does not care which.
 */
interface IssuanceHistory
{
    /**
     * @return list<CertificateIssuanceRecord> issuances touching the registered domain at or after $since
     */
    public function certificatesForRegisteredDomain(string $registeredDomain, \DateTimeImmutable $since): array;

    /**
     * @return list<CertificateIssuanceRecord> issuances of the exact identifier set at or after $since
     */
    public function certificatesForIdentifierSet(string $canonicalKey, \DateTimeImmutable $since): array;

    /**
     * @return list<CertificateIssuanceRecord> issuances ordered by the account at or after $since
     */
    public function ordersForAccount(string $accountId, \DateTimeImmutable $since): array;

    /**
     * @return list<ValidationFailureRecord> failures for the identifier and account at or after $since
     */
    public function validationFailures(string $identifier, string $accountId, \DateTimeImmutable $since): array;

    /**
     * Whether the exact identifier set has ever been issued, used to classify a
     * planned request as a renewal regardless of any window.
     */
    public function hasIssuedIdentifierSet(string $canonicalKey): bool;
}
