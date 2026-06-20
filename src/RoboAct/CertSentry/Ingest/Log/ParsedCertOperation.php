<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest\Log;

/**
 * One certificate operation recovered from a log line.
 *
 * This is the log parser's output and the importer's input: just the facts a log
 * line can be trusted to carry. It stops short of an ObservedCertificate because
 * a log line does not name the issuing account; the importer supplies that from
 * the server's known Let's Encrypt account before handing the observation on.
 */
final class ParsedCertOperation
{
    /**
     * @param list<string> $identifiers
     */
    public function __construct(
        private readonly \DateTimeImmutable $occurredAt,
        private readonly array $identifiers,
        private readonly bool $isRenewal,
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

    public function isRenewal(): bool
    {
        return $this->isRenewal;
    }
}
