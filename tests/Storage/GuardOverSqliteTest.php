<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Storage;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Guard\CertificateRequestGuard;
use RoboAct\CertSentry\Guard\PlannedIssuance;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;
use RoboAct\CertSentry\Storage\SqliteIssuanceHistory;

/**
 * Proves the SQLite ledger substitutes cleanly for the in-memory one: the guard
 * is unchanged and reaches the same verdict against a real database. This is the
 * Liskov check that the contract tests imply but only an end-to-end run confirms.
 */
final class GuardOverSqliteTest extends TestCase
{
    public function testGuardBlocksOnRegisteredDomainLimitUsingSqliteHistory(): void
    {
        $store = SqliteIssuanceHistory::inMemory();
        $now = new \DateTimeImmutable('2026-06-19 12:00:00', new \DateTimeZone('UTC'));

        for ($i = 0; $i < 50; $i++) {
            $store->recordIssuance(new CertificateIssuanceRecord(
                new \DateTimeImmutable('2026-06-19 09:00:00', new \DateTimeZone('UTC')),
                IdentifierSet::fromStrings(["host{$i}.example.com"]),
                'acct-1',
                ['example.com'],
                false
            ));
        }

        $guard = new CertificateRequestGuard(
            new RegisteredDomainResolver(PublicSuffixList::fromString("// ===BEGIN ICANN DOMAINS===\ncom\n// ===END ICANN DOMAINS===")),
            $store,
            RateLimitPolicy::letsEncryptDefaults()
        );

        $decision = $guard->evaluate(PlannedIssuance::fromStrings(['new.example.com'], 'acct-1'), $now);

        $this->assertFalse($decision->allowed());
        $this->assertTrue($decision->outcomeFor(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN)->isBlocking());
    }
}
