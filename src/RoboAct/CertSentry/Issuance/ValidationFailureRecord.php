<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance;

/**
 * One failed domain-control validation.
 *
 * Failed validations are limited per identifier per account per hour, on a
 * separate and much shorter window than issuance. They are recorded as their own
 * record type rather than folded into issuances because they carry a single
 * identifier (not a set) and never imply a certificate was produced.
 */
final class ValidationFailureRecord
{
    public function __construct(
        private readonly \DateTimeImmutable $occurredAt,
        private readonly string $identifier,
        private readonly string $accountId,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function identifier(): string
    {
        return $this->identifier;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }
}
