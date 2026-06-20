<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest;

/**
 * A certificate Plesk has issued, reported in a Plesk-agnostic shape.
 *
 * This is the single currency both ingestion sources deal in: the live event
 * reconciler and the historical log importer each translate their own raw input
 * into this one type, so the recorder downstream never learns where an issuance
 * came from. It carries only what the ledger needs and deliberately leaves
 * registered-domain resolution and renewal classification to the recorder, which
 * owns the Public Suffix List and the existing history those answers depend on.
 */
final class ObservedCertificate
{
    /**
     * @param list<string> $identifiers the certificate's identifiers, wildcards included
     * @param bool|null $isRenewal known renewal status, or null to let the recorder derive it
     */
    public function __construct(
        private readonly \DateTimeImmutable $occurredAt,
        private readonly array $identifiers,
        private readonly string $accountId,
        private readonly ?bool $isRenewal = null,
    ) {
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return list<string>
     */
    public function identifiers(): array
    {
        return $this->identifiers;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }

    /**
     * The stated renewal status, or null when the source could not tell and the
     * recorder should decide from history instead.
     */
    public function statedRenewal(): ?bool
    {
        return $this->isRenewal;
    }
}
