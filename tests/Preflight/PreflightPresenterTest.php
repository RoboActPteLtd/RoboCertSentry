<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Preflight;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Advice\AdvisorThresholds;
use RoboAct\CertSentry\Advice\Dns01Capability;
use RoboAct\CertSentry\Advice\WildcardConsolidationAdvisor;
use RoboAct\CertSentry\Guard\CertificateRequestGuard;
use RoboAct\CertSentry\Guard\PlannedIssuance;
use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\InMemoryIssuanceHistory;
use RoboAct\CertSentry\Preflight\PreflightPresenter;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\RateLimitName;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;

final class PreflightPresenterTest extends TestCase
{
    private const PSL = "// ===BEGIN ICANN DOMAINS===\ncom\nco.uk\nuk\norg\n// ===END ICANN DOMAINS===";

    private InMemoryIssuanceHistory $history;
    private RegisteredDomainResolver $resolver;
    private CertificateRequestGuard $guard;
    private PreflightPresenter $presenter;

    protected function setUp(): void
    {
        $this->history = new InMemoryIssuanceHistory();
        $this->resolver = new RegisteredDomainResolver(PublicSuffixList::fromString(self::PSL));
        $this->guard = new CertificateRequestGuard($this->resolver, $this->history, RateLimitPolicy::letsEncryptDefaults());
        $this->presenter = new PreflightPresenter();
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-19 12:00:00', new \DateTimeZone('UTC'));
    }

    private function advisor(bool $dns01): WildcardConsolidationAdvisor
    {
        $capability = new class ($dns01) implements Dns01Capability {
            public function __construct(private readonly bool $available)
            {
            }

            public function isDns01Available(): bool
            {
                return $this->available;
            }
        };

        return new WildcardConsolidationAdvisor(
            $this->resolver,
            $this->history,
            RateLimitPolicy::letsEncryptDefaults(),
            $capability,
            new AdvisorThresholds()
        );
    }

    private function seedDuplicates(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->history->recordIssuance(new CertificateIssuanceRecord(
                new \DateTimeImmutable('2026-06-19 0' . $i . ':00:00', new \DateTimeZone('UTC')),
                IdentifierSet::fromStrings(['dup.example.com']),
                'acct-1',
                ['example.com'],
                false
            ));
        }
    }

    public function testPresentsAnAllowedRequest(): void
    {
        $decision = $this->guard->evaluate(PlannedIssuance::fromStrings(['a.example.com'], 'acct-1'), $this->now());

        $view = $this->presenter->present($decision, []);

        $this->assertTrue($view->isAllowed());
        $this->assertSame('Safe to issue', $view->statusHeadline());
        $this->assertNotEmpty($view->limitRows());
    }

    public function testPresentsABlockedRequestWithHumanLimitLabels(): void
    {
        $this->seedDuplicates(5);
        $decision = $this->guard->evaluate(PlannedIssuance::fromStrings(['dup.example.com'], 'acct-1'), $this->now());

        $view = $this->presenter->present($decision, []);

        $this->assertFalse($view->isAllowed());
        $this->assertSame('Would be rate-limited', $view->statusHeadline());

        $labels = array_column($view->limitRows(), 'label');
        $this->assertContains('Duplicate certificate', $labels);
    }

    public function testRowCarriesUsageAndBlockingState(): void
    {
        $this->seedDuplicates(5);
        $decision = $this->guard->evaluate(PlannedIssuance::fromStrings(['dup.example.com'], 'acct-1'), $this->now());

        $view = $this->presenter->present($decision, []);

        $duplicateRow = null;
        foreach ($view->limitRows() as $row) {
            if ($row['limit'] === RateLimitName::DUPLICATE_CERTIFICATE) {
                $duplicateRow = $row;
            }
        }

        $this->assertNotNull($duplicateRow);
        $this->assertSame(5, $duplicateRow['used']);
        $this->assertSame(5, $duplicateRow['capacity']);
        $this->assertTrue($duplicateRow['blocking']);
        $this->assertInstanceOf(\DateTimeImmutable::class, $duplicateRow['retryAt']);
    }

    public function testCarriesWildcardSuggestionsWithTheirCaveat(): void
    {
        $this->history->recordIssuance(new CertificateIssuanceRecord(
            new \DateTimeImmutable('2026-06-15 09:00:00', new \DateTimeZone('UTC')),
            IdentifierSet::fromStrings(['a.example.com']),
            'acct-1',
            ['example.com'],
            false
        ));
        for ($i = 0; $i < 31; $i++) {
            $this->history->recordIssuance(new CertificateIssuanceRecord(
                new \DateTimeImmutable('2026-06-15 09:00:00', new \DateTimeZone('UTC')),
                IdentifierSet::fromStrings(['filler' . $i . '.example.com']),
                'acct-1',
                ['example.com'],
                false
            ));
        }

        $planned = PlannedIssuance::fromStrings(['b.example.com'], 'acct-1');
        $suggestions = $this->advisor(false)->advise($planned, $this->now());
        $this->assertNotEmpty($suggestions, 'precondition: advisor should suggest a wildcard');

        $decision = $this->guard->evaluate($planned, $this->now());
        $view = $this->presenter->present($decision, $suggestions);

        $rows = $view->suggestionRows();
        $this->assertCount(1, $rows);
        $this->assertSame('*.example.com', $rows[0]['wildcard']);
        $this->assertFalse($rows[0]['actionable']);
        $this->assertStringContainsString('DNS-01', $rows[0]['reason']);
    }
}
