<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Guard;

use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IssuanceHistory;
use RoboAct\CertSentry\PublicSuffix\Exception\DomainIsPublicSuffixException;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\LimitOutcome;
use RoboAct\CertSentry\RateLimit\RateLimit;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;

/**
 * Pre-flight checker: would this planned certificate request be rate-limited?
 *
 * This is the core of RoboCertSentry. It counts the relevant slice of issuance
 * history for each limit's sliding window and reports, per bucket, whether one
 * more request fits. The subtlety it exists to get right is renewal exemption:
 * a request whose exact identifier set was issued before is a renewal, which
 * Let's Encrypt exempts from the per-registered-domain and new-orders limits but
 * not from the duplicate-certificate limit. Classifying that correctly is the
 * difference between a useful warning and a false alarm.
 */
final class CertificateRequestGuard
{
    public function __construct(
        private readonly RegisteredDomainResolver $resolver,
        private readonly IssuanceHistory $history,
        private readonly RateLimitPolicy $policy,
    ) {
    }

    public function evaluate(PlannedIssuance $planned, \DateTimeImmutable $now): GuardDecision
    {
        $canonicalKey = $planned->identifiers()->canonicalKey();
        $isRenewal = $this->history->hasIssuedIdentifierSet($canonicalKey);
        $registeredDomains = $this->registeredDomainsOf($planned);

        $outcomes = [];
        $outcomes[] = $this->duplicateOutcome($canonicalKey, $now);

        foreach ($registeredDomains as $registeredDomain) {
            $outcomes[] = $this->registeredDomainOutcome($registeredDomain, $isRenewal, $now);
        }

        $outcomes[] = $this->newOrdersOutcome($planned->accountId(), $isRenewal, $now);

        foreach ($planned->identifiers()->identifiers() as $identifier) {
            $outcomes[] = $this->failedValidationOutcome($identifier->value(), $planned->accountId(), $now);
        }

        return new GuardDecision($outcomes, $isRenewal, $registeredDomains);
    }

    /**
     * The distinct registrable domains the request would count against. Hosts
     * that are themselves public suffixes have no registrable domain and simply
     * do not contribute a bucket.
     *
     * @return list<string>
     */
    private function registeredDomainsOf(PlannedIssuance $planned): array
    {
        $domains = [];
        foreach ($planned->identifiers()->identifiers() as $identifier) {
            try {
                $domains[$this->resolver->resolve($identifier->baseDomain())->registrableDomain()] = true;
            } catch (DomainIsPublicSuffixException) {
                continue;
            }
        }

        return array_keys($domains);
    }

    private function duplicateOutcome(string $canonicalKey, \DateTimeImmutable $now): LimitOutcome
    {
        $limit = $this->policy->limit(RateLimitName::DUPLICATE_CERTIFICATE);
        $records = $this->history->certificatesForIdentifierSet($canonicalKey, $limit->windowStart($now));

        // The duplicate limit always applies, to renewals as much as new orders.
        return $this->fillOutcome($limit, $canonicalKey, $records, true, $this->wouldOverflow($records, $limit));
    }

    private function registeredDomainOutcome(string $registeredDomain, bool $isRenewal, \DateTimeImmutable $now): LimitOutcome
    {
        $limit = $this->policy->limit(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN);
        // Renewals do not consume this bucket, so they are excluded from the count.
        $records = $this->excludeRenewals(
            $this->history->certificatesForRegisteredDomain($registeredDomain, $limit->windowStart($now))
        );

        $blocking = !$isRenewal && $this->wouldOverflow($records, $limit);

        return $this->fillOutcome($limit, $registeredDomain, $records, !$isRenewal, $blocking);
    }

    private function newOrdersOutcome(string $accountId, bool $isRenewal, \DateTimeImmutable $now): LimitOutcome
    {
        $limit = $this->policy->limit(RateLimitName::NEW_ORDERS_PER_ACCOUNT);
        $records = $this->excludeRenewals(
            $this->history->ordersForAccount($accountId, $limit->windowStart($now))
        );

        $blocking = !$isRenewal && $this->wouldOverflow($records, $limit);

        return $this->fillOutcome($limit, $accountId, $records, !$isRenewal, $blocking);
    }

    private function failedValidationOutcome(string $identifier, string $accountId, \DateTimeImmutable $now): LimitOutcome
    {
        $limit = $this->policy->limit(RateLimitName::FAILED_VALIDATIONS);
        $failures = $this->history->validationFailures($identifier, $accountId, $limit->windowStart($now));
        $used = count($failures);

        // This bucket counts failures already incurred, so being at capacity
        // already blocks the next authorization attempt; one more is not needed.
        $blocking = $used >= $limit->capacity();
        $retryAt = $blocking ? $this->retryAt($failures, $limit) : null;

        return new LimitOutcome(
            $limit->name(),
            $identifier,
            $used,
            $limit->capacity(),
            true,
            $blocking,
            $retryAt
        );
    }

    /**
     * @param list<CertificateIssuanceRecord> $records
     */
    private function fillOutcome(RateLimit $limit, string $bucketKey, array $records, bool $applicable, bool $blocking): LimitOutcome
    {
        return new LimitOutcome(
            $limit->name(),
            $bucketKey,
            count($records),
            $limit->capacity(),
            $applicable,
            $blocking,
            $blocking ? $this->retryAt($records, $limit) : null
        );
    }

    /**
     * A bucket overflows when the existing count leaves no room for one more.
     *
     * @param list<CertificateIssuanceRecord> $records
     */
    private function wouldOverflow(array $records, RateLimit $limit): bool
    {
        return count($records) + 1 > $limit->capacity();
    }

    /**
     * @param list<CertificateIssuanceRecord> $records
     * @return list<CertificateIssuanceRecord>
     */
    private function excludeRenewals(array $records): array
    {
        return array_values(array_filter(
            $records,
            static fn (CertificateIssuanceRecord $r): bool => !$r->isRenewal()
        ));
    }

    /**
     * In a sliding window the bucket regains a slot when its oldest event ages
     * out, so the soonest retry is the earliest counted event plus the window.
     *
     * @param list<CertificateIssuanceRecord|\RoboAct\CertSentry\Issuance\ValidationFailureRecord> $records
     */
    private function retryAt(array $records, RateLimit $limit): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($records as $record) {
            $occurredAt = $record->occurredAt();
            if ($earliest === null || $occurredAt < $earliest) {
                $earliest = $occurredAt;
            }
        }

        if ($earliest === null) {
            return null;
        }

        return $earliest->add(new \DateInterval('PT' . $limit->windowSeconds() . 'S'));
    }
}
