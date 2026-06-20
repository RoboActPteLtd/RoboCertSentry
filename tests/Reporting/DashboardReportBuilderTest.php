<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;
use RoboAct\CertSentry\Reporting\DashboardReportBuilder;
use RoboAct\CertSentry\Reporting\UsageReportBuilder;

final class DashboardReportBuilderTest extends TestCase
{
    private InMemoryIssuanceHistory $history;
    private DashboardReportBuilder $builder;

    protected function setUp(): void
    {
        $this->history = new InMemoryIssuanceHistory();
        $this->builder = new DashboardReportBuilder(
            new UsageReportBuilder($this->history, RateLimitPolicy::letsEncryptDefaults())
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-19 12:00:00', new \DateTimeZone('UTC'));
    }

    private function seed(string $registeredDomain): void
    {
        $this->history->recordIssuance(new CertificateIssuanceRecord(
            new \DateTimeImmutable('2026-06-18 09:00:00', new \DateTimeZone('UTC')),
            IdentifierSet::fromStrings(['a.' . $registeredDomain]),
            'acct-1',
            [$registeredDomain],
            false
        ));
    }

    public function testBuildsOneRegisteredDomainUsageRowPerDistinctDomainSorted(): void
    {
        $this->seed('example.com');
        $this->seed('other.org');

        $report = $this->builder->build(['other.org', 'example.com', 'example.com'], 'acct-1', $this->now());

        $domains = array_map(static fn ($u) => $u->bucketKey(), $report->domainUsages());
        $this->assertSame(['example.com', 'other.org'], $domains, 'domains are de-duplicated and sorted');
    }

    public function testIncludesTheAccountWideNewOrdersUsage(): void
    {
        // The new-orders limit is a 3-hour window, so this must be recent.
        $this->history->recordIssuance(new CertificateIssuanceRecord(
            new \DateTimeImmutable('2026-06-19 11:00:00', new \DateTimeZone('UTC')),
            IdentifierSet::fromStrings(['a.example.com']),
            'acct-1',
            ['example.com'],
            false
        ));

        $report = $this->builder->build(['example.com'], 'acct-1', $this->now());

        $this->assertSame(RateLimitName::NEW_ORDERS_PER_ACCOUNT, $report->accountUsage()->limit());
        $this->assertSame(1, $report->accountUsage()->used());
    }
}
