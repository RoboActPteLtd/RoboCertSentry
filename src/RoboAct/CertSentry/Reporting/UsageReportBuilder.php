<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Reporting;

use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IssuanceHistory;
use RoboAct\CertSentry\RateLimit\RateLimit;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;

/**
 * Builds the standing usage picture the dashboard shows per registered domain.
 *
 * It counts the same slices the guard does and applies the same renewal exemption,
 * so the panel and the pre-flight check can never disagree about how full a bucket
 * is. The difference is intent: there is no planned request here, just a snapshot
 * of where each bucket sits and when it eases.
 */
final class UsageReportBuilder
{
    public function __construct(
        private readonly IssuanceHistory $history,
        private readonly RateLimitPolicy $policy,
    ) {
    }

    public function registeredDomainUsage(string $registeredDomain, \DateTimeImmutable $now): LimitUsage
    {
        $limit = $this->policy->limit(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN);
        $counted = $this->excludeRenewals(
            $this->history->certificatesForRegisteredDomain($registeredDomain, $limit->windowStart($now))
        );

        return $this->usage($limit, $registeredDomain, $counted);
    }

    public function newOrdersUsage(string $accountId, \DateTimeImmutable $now): LimitUsage
    {
        $limit = $this->policy->limit(RateLimitName::NEW_ORDERS_PER_ACCOUNT);
        $counted = $this->excludeRenewals(
            $this->history->ordersForAccount($accountId, $limit->windowStart($now))
        );

        return $this->usage($limit, $accountId, $counted);
    }

    /**
     * @param list<CertificateIssuanceRecord> $counted
     */
    private function usage(RateLimit $limit, string $bucketKey, array $counted): LimitUsage
    {
        return new LimitUsage(
            $limit->name(),
            $bucketKey,
            count($counted),
            $limit->capacity(),
            $this->nextSlotAt($counted, $limit)
        );
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
     * @param list<CertificateIssuanceRecord> $counted
     */
    private function nextSlotAt(array $counted, RateLimit $limit): ?\DateTimeImmutable
    {
        $earliest = null;
        foreach ($counted as $record) {
            if ($earliest === null || $record->occurredAt() < $earliest) {
                $earliest = $record->occurredAt();
            }
        }

        if ($earliest === null) {
            return null;
        }

        return $earliest->add(new \DateInterval('PT' . $limit->windowSeconds() . 'S'));
    }
}
