<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Guard;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Guard\CertificateRequestGuard;
use RoboAct\CertSentry\Guard\PlannedIssuance;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\Issuance\ValidationFailureRecord;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;

final class CertificateRequestGuardTest extends TestCase
{
    private const PSL = "// ===BEGIN ICANN DOMAINS===\ncom\nco.uk\nuk\norg\n// ===END ICANN DOMAINS===";

    private InMemoryIssuanceHistory $history;
    private CertificateRequestGuard $guard;

    protected function setUp(): void
    {
        $this->history = new InMemoryIssuanceHistory();
        $this->guard = new CertificateRequestGuard(
            new RegisteredDomainResolver(PublicSuffixList::fromString(self::PSL)),
            $this->history,
            RateLimitPolicy::letsEncryptDefaults()
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-19 12:00:00', new \DateTimeZone('UTC'));
    }

    private function seedIssuance(string $time, array $names, string $account, array $registeredDomains, bool $isRenewal = false): void
    {
        $this->history->recordIssuance(new CertificateIssuanceRecord(
            new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
            IdentifierSet::fromStrings($names),
            $account,
            $registeredDomains,
            $isRenewal
        ));
    }

    public function testAllowsAFreshRequestWithinAllLimits(): void
    {
        $decision = $this->guard->evaluate(
            PlannedIssuance::fromStrings(['a.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertTrue($decision->allowed());
        $this->assertSame(0, $decision->outcomeFor(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN)->used());
    }

    public function testBlocksWhenRegisteredDomainBucketIsFull(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->seedIssuance('2026-06-19 09:00:00', ["host{$i}.example.com"], 'acct-1', ['example.com']);
        }

        $decision = $this->guard->evaluate(
            PlannedIssuance::fromStrings(['new.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertFalse($decision->allowed());
        $outcome = $decision->outcomeFor(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN);
        $this->assertTrue($outcome->isBlocking());
        $this->assertNotNull($outcome->retryAt());
    }

    public function testRenewalIsExemptFromTheRegisteredDomainLimit(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->seedIssuance('2026-06-19 09:00:00', ["host{$i}.example.com"], 'acct-1', ['example.com']);
        }
        // The planned set was issued before, so it is a renewal.
        $this->seedIssuance('2026-06-15 09:00:00', ['renew.example.com'], 'acct-1', ['example.com']);

        $decision = $this->guard->evaluate(
            PlannedIssuance::fromStrings(['renew.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertTrue($decision->isRenewal());
        $this->assertFalse($decision->outcomeFor(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN)->isBlocking());
    }

    public function testBlocksOnDuplicateCertificateLimitEvenForRenewals(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedIssuance('2026-06-17 09:00:00', ['dup.example.com'], 'acct-1', ['example.com']);
        }

        $decision = $this->guard->evaluate(
            PlannedIssuance::fromStrings(['dup.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertFalse($decision->allowed());
        $this->assertTrue($decision->outcomeFor(RateLimitName::DUPLICATE_CERTIFICATE)->isBlocking());
    }

    public function testBlocksWhenAccountOrderBucketIsFull(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->seedIssuance('2026-06-19 11:00:00', ["n{$i}.example.com"], 'acct-1', ['example.com']);
        }

        $decision = $this->guard->evaluate(
            PlannedIssuance::fromStrings(['late.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertTrue($decision->outcomeFor(RateLimitName::NEW_ORDERS_PER_ACCOUNT)->isBlocking());
    }

    public function testWarnsWhenIdentifierIsAtItsFailedValidationCap(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->history->recordValidationFailure(new ValidationFailureRecord(
                new \DateTimeImmutable('2026-06-19 11:40:00', new \DateTimeZone('UTC')),
                'a.example.com',
                'acct-1'
            ));
        }

        $decision = $this->guard->evaluate(
            PlannedIssuance::fromStrings(['a.example.com'], 'acct-1'),
            $this->now()
        );

        $this->assertTrue($decision->outcomeFor(RateLimitName::FAILED_VALIDATIONS)->isBlocking());
    }

    public function testReportsEveryRegisteredDomainAMultiNameCertTouches(): void
    {
        $decision = $this->guard->evaluate(
            PlannedIssuance::fromStrings(['a.example.com', 'b.other.org'], 'acct-1'),
            $this->now()
        );

        $this->assertEqualsCanonicalizing(
            ['example.com', 'other.org'],
            $decision->registeredDomains()
        );
    }
}
