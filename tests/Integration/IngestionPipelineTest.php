<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Advice\AdvisorThresholds;
use RoboAct\CertSentry\Advice\Dns01Capability;
use RoboAct\CertSentry\Advice\WildcardConsolidationAdvisor;
use RoboAct\CertSentry\Guard\CertificateRequestGuard;
use RoboAct\CertSentry\Guard\PlannedIssuance;
use RoboAct\CertSentry\Ingest\CertificateInventory;
use RoboAct\CertSentry\Ingest\CertificateObservationRecorder;
use RoboAct\CertSentry\Ingest\CertificateSync;
use RoboAct\CertSentry\Ingest\Log\CertOperationLogParser;
use RoboAct\CertSentry\Ingest\Log\LogImporter;
use RoboAct\CertSentry\Ingest\ObservedCertificate;
use RoboAct\CertSentry\Preflight\PreflightPresenter;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;
use RoboAct\CertSentry\Reporting\DashboardReportBuilder;
use RoboAct\CertSentry\Reporting\UsageReportBuilder;
use RoboAct\CertSentry\Storage\SqliteIssuanceHistory;

/**
 * Proves the whole glue chain over the real durable store: backfill and live
 * sources converge on one ledger without double-counting, and that ledger then
 * drives both the dashboard and the pre-flight check. This is the test that would
 * have caught a seam that only worked against the in-memory reference store.
 */
final class IngestionPipelineTest extends TestCase
{
    private const PSL = "// ===BEGIN ICANN DOMAINS===\ncom\nco.uk\nuk\norg\n// ===END ICANN DOMAINS===";

    private SqliteIssuanceHistory $history;
    private RegisteredDomainResolver $resolver;
    private CertificateObservationRecorder $recorder;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for the durable ledger.');
        }

        $this->history = SqliteIssuanceHistory::inMemory();
        $this->resolver = new RegisteredDomainResolver(PublicSuffixList::fromString(self::PSL));
        $this->recorder = new CertificateObservationRecorder($this->resolver, $this->history, $this->history);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-19 12:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * @param list<ObservedCertificate> $certs
     */
    private function inventoryOf(array $certs): CertificateInventory
    {
        return new class ($certs) implements CertificateInventory {
            /** @param list<ObservedCertificate> $certs */
            public function __construct(private readonly array $certs)
            {
            }

            public function observedCertificates(): array
            {
                return $this->certs;
            }
        };
    }

    public function testBackfillAndLiveSourcesConvergeWithoutDoubleCounting(): void
    {
        // Backfill: a log naming two historical single-host issuances this week.
        $log = implode("\n", [
            '2026-06-15 09:00:00 INFO [letsencrypt] Certificate issued for a.example.com',
            '2026-06-16 09:00:00 INFO [letsencrypt] Certificate issued for b.example.com',
        ]);
        $importer = new LogImporter(new CertOperationLogParser(new \DateTimeZone('UTC')), $this->recorder);
        $importSummary = $importer->import($log, 'plesk-default');
        $this->assertSame(2, $importSummary->recordedCount());

        // Live: the inventory still holds a.example.com (already known) and a new one.
        $sync = new CertificateSync($this->inventoryOf([
            new ObservedCertificate(new \DateTimeImmutable('2026-06-15 09:00:00', new \DateTimeZone('UTC')), ['a.example.com'], 'plesk-default'),
            new ObservedCertificate(new \DateTimeImmutable('2026-06-17 09:00:00', new \DateTimeZone('UTC')), ['c.example.com'], 'plesk-default'),
        ]), $this->recorder);
        $syncSummary = $sync->reconcile();

        $this->assertSame(1, $syncSummary->recordedCount(), 'only the genuinely new certificate is recorded');
        $this->assertSame(1, $syncSummary->skippedCount(), 'the overlapping certificate is recognised, not duplicated');

        $usage = (new UsageReportBuilder($this->history, RateLimitPolicy::letsEncryptDefaults()))
            ->registeredDomainUsage('example.com', $this->now());
        $this->assertSame(3, $usage->used(), 'three distinct issuances across both sources');
    }

    public function testLedgerDrivesTheDashboardAndPreflight(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->recorder->record(new ObservedCertificate(
                new \DateTimeImmutable('2026-06-15 09:00:00', new \DateTimeZone('UTC')),
                ['host' . $i . '.example.com'],
                'plesk-default'
            ));
        }

        $dashboard = (new DashboardReportBuilder(new UsageReportBuilder($this->history, RateLimitPolicy::letsEncryptDefaults())))
            ->build(['example.com'], 'plesk-default', $this->now());
        $this->assertSame(31, $dashboard->domainUsages()[0]->used());

        $guard = new CertificateRequestGuard($this->resolver, $this->history, RateLimitPolicy::letsEncryptDefaults());
        $advisor = new WildcardConsolidationAdvisor(
            $this->resolver,
            $this->history,
            RateLimitPolicy::letsEncryptDefaults(),
            $this->dns01(true),
            new AdvisorThresholds()
        );

        $planned = PlannedIssuance::fromStrings(['host99.example.com'], 'plesk-default');
        $view = (new PreflightPresenter())->present(
            $guard->evaluate($planned, $this->now()),
            $advisor->advise($planned, $this->now())
        );

        $this->assertTrue($view->isAllowed(), 'still within the 50/week registered-domain limit');
        $this->assertNotEmpty($view->suggestionRows(), 'pressure is high enough to suggest a wildcard');
        $this->assertTrue($view->suggestionRows()[0]['actionable'], 'DNS-01 available, so the wildcard is actionable');
    }

    private function dns01(bool $available): Dns01Capability
    {
        return new class ($available) implements Dns01Capability {
            public function __construct(private readonly bool $available)
            {
            }

            public function isDns01Available(): bool
            {
                return $this->available;
            }
        };
    }
}
