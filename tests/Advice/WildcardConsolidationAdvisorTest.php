<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Advice;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Advice\AdvisorThresholds;
use RoboAct\CertSentry\Advice\Dns01Capability;
use RoboAct\CertSentry\Advice\WildcardConsolidationAdvisor;
use RoboAct\CertSentry\Guard\PlannedIssuance;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;

final class WildcardConsolidationAdvisorTest extends TestCase
{
    private const PSL = "// ===BEGIN ICANN DOMAINS===\ncom\n// ===END ICANN DOMAINS===";

    private InMemoryIssuanceHistory $history;

    protected function setUp(): void
    {
        $this->history = new InMemoryIssuanceHistory();
    }

    private function dns01(bool $available): Dns01Capability
    {
        return new class ($available) implements Dns01Capability {
            public function __construct(private bool $available)
            {
            }

            public function isDns01Available(): bool
            {
                return $this->available;
            }
        };
    }

    private function advisor(bool $dns01, AdvisorThresholds $thresholds): WildcardConsolidationAdvisor
    {
        return new WildcardConsolidationAdvisor(
            new RegisteredDomainResolver(PublicSuffixList::fromString(self::PSL)),
            $this->history,
            RateLimitPolicy::letsEncryptDefaults(),
            $this->dns01($dns01),
            $thresholds
        );
    }

    private function seedSubdomainCert(string $host): void
    {
        $this->history->recordIssuance(new CertificateIssuanceRecord(
            new \DateTimeImmutable('2026-06-18 09:00:00', new \DateTimeZone('UTC')),
            IdentifierSet::fromStrings([$host]),
            'acct-1',
            ['example.com'],
            false
        ));
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-19 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testRecommendsWildcardWhenManySubdomainsAndDnsAvailable(): void
    {
        $this->seedSubdomainCert('a.example.com');
        $this->seedSubdomainCert('b.example.com');
        $this->seedSubdomainCert('c.example.com');

        $suggestions = $this->advisor(true, new AdvisorThresholds(2, 50))->advise(
            PlannedIssuance::fromStrings(['d.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertCount(1, $suggestions);
        $this->assertSame('*.example.com', $suggestions[0]->wildcard());
        $this->assertTrue($suggestions[0]->isActionable());
        $this->assertSame(3, $suggestions[0]->bucketsSaved());
    }

    public function testReportsConstraintWhenDnsUnavailable(): void
    {
        $this->seedSubdomainCert('a.example.com');
        $this->seedSubdomainCert('b.example.com');

        $suggestions = $this->advisor(false, new AdvisorThresholds(2, 50))->advise(
            PlannedIssuance::fromStrings(['c.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertCount(1, $suggestions);
        $this->assertFalse($suggestions[0]->isActionable());
        $this->assertStringContainsStringIgnoringCase('dns-01', $suggestions[0]->reason());
    }

    public function testStaysSilentWhenTooFewSubdomainsToConsolidate(): void
    {
        $suggestions = $this->advisor(true, new AdvisorThresholds(2, 50))->advise(
            PlannedIssuance::fromStrings(['only.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertSame([], $suggestions);
    }

    public function testStaysSilentWhenNotApproachingTheLimit(): void
    {
        $this->seedSubdomainCert('a.example.com');
        $this->seedSubdomainCert('b.example.com');
        $this->seedSubdomainCert('c.example.com');

        // Headroom threshold of 5 means "approaching" needs used >= 45 of 50.
        $suggestions = $this->advisor(true, new AdvisorThresholds(2, 5))->advise(
            PlannedIssuance::fromStrings(['d.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertSame([], $suggestions);
    }
}
