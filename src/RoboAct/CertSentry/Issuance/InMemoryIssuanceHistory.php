<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance;

/**
 * A volatile issuance ledger backed by plain arrays.
 *
 * This is the reference implementation the rate-limit logic is tested against,
 * deliberately free of any storage concern. Because it shares the IssuanceHistory
 * contract with the SQLite-backed store, every limit and guard test that passes
 * here is exercising the exact behaviour production relies on, just without a
 * database in the loop.
 */
final class InMemoryIssuanceHistory implements IssuanceHistory, IssuanceRecorder
{
    /** @var list<CertificateIssuanceRecord> */
    private array $issuances = [];

    /** @var list<ValidationFailureRecord> */
    private array $failures = [];

    public function recordIssuance(CertificateIssuanceRecord $record): void
    {
        $this->issuances[] = $record;
    }

    public function recordValidationFailure(ValidationFailureRecord $record): void
    {
        $this->failures[] = $record;
    }

    public function certificatesForRegisteredDomain(string $registeredDomain, \DateTimeImmutable $since): array
    {
        return $this->filterIssuances(
            $since,
            static fn (CertificateIssuanceRecord $r): bool => in_array($registeredDomain, $r->registeredDomains(), true)
        );
    }

    public function certificatesForIdentifierSet(string $canonicalKey, \DateTimeImmutable $since): array
    {
        return $this->filterIssuances(
            $since,
            static fn (CertificateIssuanceRecord $r): bool => $r->identifiers()->canonicalKey() === $canonicalKey
        );
    }

    public function ordersForAccount(string $accountId, \DateTimeImmutable $since): array
    {
        return $this->filterIssuances(
            $since,
            static fn (CertificateIssuanceRecord $r): bool => $r->accountId() === $accountId
        );
    }

    public function validationFailures(string $identifier, string $accountId, \DateTimeImmutable $since): array
    {
        $matches = [];
        foreach ($this->failures as $failure) {
            if ($failure->occurredAt() < $since) {
                continue;
            }
            if ($failure->identifier() === $identifier && $failure->accountId() === $accountId) {
                $matches[] = $failure;
            }
        }

        return $matches;
    }

    public function hasIssuedIdentifierSet(string $canonicalKey): bool
    {
        foreach ($this->issuances as $issuance) {
            if ($issuance->identifiers()->canonicalKey() === $canonicalKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(CertificateIssuanceRecord): bool $predicate
     * @return list<CertificateIssuanceRecord>
     */
    private function filterIssuances(\DateTimeImmutable $since, callable $predicate): array
    {
        $matches = [];
        foreach ($this->issuances as $issuance) {
            // "since" is inclusive: a record exactly at the window edge still counts.
            if ($issuance->occurredAt() < $since) {
                continue;
            }
            if ($predicate($issuance)) {
                $matches[] = $issuance;
            }
        }

        return $matches;
    }
}
