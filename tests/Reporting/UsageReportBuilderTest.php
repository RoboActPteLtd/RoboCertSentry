<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Reporting;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;
use RoboAct\CertSentry\Reporting\UsageReportBuilder;

final class UsageReportBuilderTest extends TestCase
{
    private InMemoryIssuanceHistory $history;
    private UsageReportBuilder $builder;

    protected function setUp(): void
    {
        $this->history = new InMemoryIssuanceHistory();
        $this->builder = new UsageReportBuilder($this->history, RateLimitPolicy::letsEncryptDefaults());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-19 12:00:00', new \DateTimeZone('UTC'));
    }

    private function seed(string $time, array $names, string $account, array $registeredDomains, bool $isRenewal = false): void
    {
        $this->history->recordIssuance(new CertificateIssuanceRecord(
            new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
            IdentifierSet::fromStrings($names),
            $account,
            $registeredDomains,
            $isRenewal
        ));
    }

    public function testReportsRegisteredDomainUsageAgainstTheWeeklyLimit(): void
    {
        $this->seed('2026-06-18 10:00:00', ['a.example.com'], 'acct-1', ['example.com']);
        $this->seed('2026-06-17 10:00:00', ['b.example.com'], 'acct-1', ['example.com']);

        $usage = $this->builder->registeredDomainUsage('example.com', $this->now());

        $this->assertSame(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN, $usage->limit());
        $this->assertSame('example.com', $usage->bucketKey());
        $this->assertSame(2, $usage->used());
        $this->assertSame(50, $usage->capacity());
        $this->assertSame(48, $usage->remaining());
        $this->assertFalse($usage->isAtCapacity());
    }

    public function testRenewalsDoNotConsumeTheRegisteredDomainBucket(): void
    {
        $this->seed('2026-06-18 10:00:00', ['a.example.com'], 'acct-1', ['example.com']);
        $this->seed('2026-06-18 11:00:00', ['a.example.com'], 'acct-1', ['example.com'], true);

        $usage = $this->builder->registeredDomainUsage('example.com', $this->now());

        $this->assertSame(1, $usage->used(), 'the renewal is exempt from the per-registered-domain limit');
    }

    public function testIssuancesOlderThanTheWindowAreNotCounted(): void
    {
        $this->seed('2026-06-01 10:00:00', ['old.example.com'], 'acct-1', ['example.com']);
        $this->seed('2026-06-18 10:00:00', ['new.example.com'], 'acct-1', ['example.com']);

        $usage = $this->builder->registeredDomainUsage('example.com', $this->now());

        $this->assertSame(1, $usage->used());
    }

    public function testProjectsWhenTheNextSlotFreesFromTheOldestCountedEvent(): void
    {
        $this->seed('2026-06-15 09:00:00', ['a.example.com'], 'acct-1', ['example.com']);
        $this->seed('2026-06-17 09:00:00', ['b.example.com'], 'acct-1', ['example.com']);

        $usage = $this->builder->registeredDomainUsage('example.com', $this->now());

        $this->assertEquals(
            new \DateTimeImmutable('2026-06-22 09:00:00', new \DateTimeZone('UTC')),
            $usage->nextSlotAt(),
            'a slot reopens one week after the oldest counted issuance'
        );
    }

    public function testNextSlotIsNullWhenNothingIsCounted(): void
    {
        $usage = $this->builder->registeredDomainUsage('example.com', $this->now());

        $this->assertSame(0, $usage->used());
        $this->assertNull($usage->nextSlotAt());
    }

    public function testReportsNewOrdersUsagePerAccount(): void
    {
        $this->seed('2026-06-19 11:00:00', ['a.example.com'], 'acct-1', ['example.com']);
        $this->seed('2026-06-19 11:30:00', ['b.other.org'], 'acct-1', ['other.org']);
        $this->seed('2026-06-19 11:30:00', ['c.elsewhere.com'], 'acct-2', ['elsewhere.com']);

        $usage = $this->builder->newOrdersUsage('acct-1', $this->now());

        $this->assertSame(RateLimitName::NEW_ORDERS_PER_ACCOUNT, $usage->limit());
        $this->assertSame(2, $usage->used());
        $this->assertSame(300, $usage->capacity());
    }
}
