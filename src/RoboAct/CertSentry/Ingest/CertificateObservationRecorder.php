<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest;

use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\IssuanceHistory;
use RoboAct\CertSentry\Issuance\IssuanceRecorder;
use RoboAct\CertSentry\PublicSuffix\Exception\DomainIsPublicSuffixException;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;

/**
 * The one place an observed certificate becomes a ledger record.
 *
 * Both ingestion sources converge here so the rules that decide what a record
 * means live in exactly one tested place: which registered domains an issuance
 * counts against, whether it is a renewal, and whether it is the same event we
 * have already seen. Centralising this is what lets the live reconciler and the
 * log importer share behaviour and stay safely idempotent, since reconciliation
 * deliberately re-reports issuances that may already be recorded.
 */
final class CertificateObservationRecorder
{
    public function __construct(
        private readonly RegisteredDomainResolver $resolver,
        private readonly IssuanceHistory $history,
        private readonly IssuanceRecorder $recorder,
        /**
         * Two reports of the same identifier set this close together are taken to
         * be the same issuance rather than two genuine ones, because Let's Encrypt
         * would itself refuse a real duplicate that fast. The window absorbs the
         * clock skew between a log line's time and an event's time.
         */
        private readonly int $duplicateWindowSeconds = 300,
    ) {
    }

    public function record(ObservedCertificate $observation): RecordOutcome
    {
        $identifiers = IdentifierSet::fromStrings($observation->identifiers());

        if ($this->isAlreadyRecorded($identifiers->canonicalKey(), $observation->occurredAt())) {
            return RecordOutcome::DUPLICATE_SKIPPED;
        }

        $this->recorder->recordIssuance(new CertificateIssuanceRecord(
            $observation->occurredAt(),
            $identifiers,
            $observation->accountId(),
            $this->registeredDomainsOf($identifiers),
            $observation->statedRenewal() ?? $this->history->hasIssuedIdentifierSet($identifiers->canonicalKey()),
        ));

        return RecordOutcome::RECORDED;
    }

    private function isAlreadyRecorded(string $canonicalKey, \DateTimeImmutable $occurredAt): bool
    {
        $window = new \DateInterval('PT' . $this->duplicateWindowSeconds . 'S');
        $since = $occurredAt->sub($window);
        $until = $occurredAt->add($window);

        foreach ($this->history->certificatesForIdentifierSet($canonicalKey, $since) as $existing) {
            if ($existing->occurredAt() <= $until) {
                return true;
            }
        }

        return false;
    }

    /**
     * The distinct registrable domains the certificate counts against. Identifiers
     * that are themselves public suffixes have no registrable domain and simply
     * contribute no bucket.
     *
     * @return list<string>
     */
    private function registeredDomainsOf(IdentifierSet $identifiers): array
    {
        $domains = [];
        foreach ($identifiers->identifiers() as $identifier) {
            try {
                $domains[$this->resolver->resolve($identifier->baseDomain())->registrableDomain()] = true;
            } catch (DomainIsPublicSuffixException) {
                continue;
            }
        }

        return array_keys($domains);
    }
}
