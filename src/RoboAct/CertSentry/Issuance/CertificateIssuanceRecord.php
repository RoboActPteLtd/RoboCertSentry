<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance;

/**
 * One certificate issuance that has already happened.
 *
 * The guard predicts the future by counting the past, so the past has to be
 * recorded in the exact shape the limits count by: the identifier set (for the
 * duplicate limit), the registered domains it touched (for the per-registered-
 * domain limit), the account (for the new-orders limit), and whether it was a
 * renewal (which exempts it from the first two). Pre-computing the registered
 * domains at record time keeps the hot query path from re-running PSL resolution.
 */
final class CertificateIssuanceRecord
{
    /**
     * @param list<string> $registeredDomains registrable domains this certificate counted against
     */
    public function __construct(
        private readonly \DateTimeImmutable $occurredAt,
        private readonly IdentifierSet $identifiers,
        private readonly string $accountId,
        private readonly array $registeredDomains,
        private readonly bool $isRenewal,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function identifiers(): IdentifierSet
    {
        return $this->identifiers;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    /**
     * @return list<string>
     */
    public function registeredDomains(): array
    {
        return $this->registeredDomains;
    }

    /**
     * Whether this issuance was a renewal, which Let's Encrypt exempts from the
     * per-registered-domain and new-orders limits.
     */
    public function isRenewal(): bool
    {
        return $this->isRenewal;
    }
}
