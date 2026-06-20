<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Reporting;

/**
 * The whole usage picture the dashboard renders in one pass.
 *
 * Grouping the per-domain rows with the single account-wide row lets the template
 * iterate without deciding which limits are per-domain and which are server-wide;
 * that classification is made here, once, where the report is assembled.
 */
final class DashboardReport
{
    /**
     * @param list<LimitUsage> $domainUsages
     */
    public function __construct(
        private readonly array $domainUsages,
        private readonly LimitUsage $accountUsage,
    ) {
    }

    /**
     * @return list<LimitUsage>
     */
    public function domainUsages(): array
    {
        return $this->domainUsages;
    }

    public function accountUsage(): LimitUsage
    {
        return $this->accountUsage;
    }
}
