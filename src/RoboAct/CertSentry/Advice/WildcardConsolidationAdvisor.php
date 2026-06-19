<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Advice;

use RoboAct\CertSentry\Guard\PlannedIssuance;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\Identifier;
use RoboAct\CertSentry\Issuance\IssuanceHistory;
use RoboAct\CertSentry\PublicSuffix\Exception\DomainIsPublicSuffixException;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;

/**
 * Suggests collapsing many per-subdomain certificates into one wildcard.
 *
 * This is the feature that turns the guard from a calculator into an adviser.
 * When an operator keeps issuing a separate certificate per subdomain under one
 * registered domain, each draws from the same 50-per-week bucket; a single
 * "*.example.com" draws once. The advisor spots that pattern as the bucket
 * tightens and recommends the wildcard, but only as actionable when DNS-01 is
 * available, because that is the only challenge a wildcard can be issued through.
 */
final class WildcardConsolidationAdvisor
{
    public function __construct(
        private readonly RegisteredDomainResolver $resolver,
        private readonly IssuanceHistory $history,
        private readonly RateLimitPolicy $policy,
        private readonly Dns01Capability $dns01,
        private readonly AdvisorThresholds $thresholds,
    ) {
    }

    /**
     * @return list<WildcardSuggestion>
     */
    public function advise(PlannedIssuance $planned, \DateTimeImmutable $now): array
    {
        $limit = $this->policy->limit(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN);
        $since = $limit->windowStart($now);

        $suggestions = [];
        foreach ($this->registeredDomainsOf($planned) as $registeredDomain) {
            $hosts = $this->consolidatableHosts($registeredDomain, $planned, $since);

            $used = count($this->history->certificatesForRegisteredDomain($registeredDomain, $since));
            $remaining = $limit->capacity() - $used;
            $approaching = $remaining <= $this->thresholds->headroomThreshold();

            if (count($hosts) < $this->thresholds->minimumToConsolidate() || !$approaching) {
                continue;
            }

            $suggestions[] = $this->buildSuggestion($registeredDomain, $hosts);
        }

        return $suggestions;
    }

    /**
     * The distinct single-host subdomain certificates under one registered domain
     * that a wildcard would replace: those already issued plus those planned now.
     *
     * @return list<string>
     */
    private function consolidatableHosts(string $registeredDomain, PlannedIssuance $planned, \DateTimeImmutable $since): array
    {
        $hosts = [];

        foreach ($this->history->certificatesForRegisteredDomain($registeredDomain, $since) as $record) {
            $host = $this->soleSubdomainHost($record, $registeredDomain);
            if ($host !== null) {
                $hosts[$host] = true;
            }
        }

        foreach ($planned->identifiers()->identifiers() as $identifier) {
            if ($this->isSubdomainHost($identifier, $registeredDomain)) {
                $hosts[$identifier->value()] = true;
            }
        }

        return array_keys($hosts);
    }

    /**
     * A previously issued certificate qualifies only if it covered exactly one
     * non-wildcard subdomain; a multi-name certificate is not a separate per-host
     * certificate the wildcard would tidily absorb. Renewals are skipped because
     * they do not add bucket pressure.
     */
    private function soleSubdomainHost(CertificateIssuanceRecord $record, string $registeredDomain): ?string
    {
        if ($record->isRenewal()) {
            return null;
        }

        $identifiers = $record->identifiers()->identifiers();
        if (count($identifiers) !== 1) {
            return null;
        }

        $identifier = $identifiers[0];

        return $this->isSubdomainHost($identifier, $registeredDomain) ? $identifier->value() : null;
    }

    private function isSubdomainHost(Identifier $identifier, string $registeredDomain): bool
    {
        if ($identifier->isWildcard()) {
            return false;
        }

        $value = $identifier->value();

        return $value !== $registeredDomain && str_ends_with($value, '.' . $registeredDomain);
    }

    /**
     * @param list<string> $hosts
     */
    private function buildSuggestion(string $registeredDomain, array $hosts): WildcardSuggestion
    {
        $wildcard = '*.' . $registeredDomain;
        $count = count($hosts);

        if ($this->dns01->isDns01Available()) {
            return new WildcardSuggestion(
                $registeredDomain,
                $wildcard,
                $hosts,
                true,
                sprintf(
                    'DNS-01 is available. Consolidate %d certificates into %s to reclaim %d registered-domain slots.',
                    $count,
                    $wildcard,
                    $count - 1
                )
            );
        }

        return new WildcardSuggestion(
            $registeredDomain,
            $wildcard,
            $hosts,
            false,
            sprintf(
                'Wildcard certificates require the DNS-01 challenge, which is not configured. Add a DNS-01 capable plugin to consolidate %d certificates into %s.',
                $count,
                $wildcard
            )
        );
    }

    /**
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
}
