<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Reporting;

/**
 * Assembles the dashboard's usage report across a server's registered domains.
 *
 * The controller knows which registered domains exist on the box; this turns that
 * bare list into the standing usage of every relevant limit. Keeping the loop and
 * the de-duplication here means the Plesk controller stays a thin pass-through and
 * the table-building logic is covered by tests rather than living in a template.
 */
final class DashboardReportBuilder
{
    public function __construct(
        private readonly UsageReportBuilder $usage,
    ) {
    }

    /**
     * @param list<string> $registeredDomains
     */
    public function build(array $registeredDomains, string $accountId, \DateTimeImmutable $now): DashboardReport
    {
        $distinct = array_values(array_unique($registeredDomains));
        sort($distinct);

        $domainUsages = array_map(
            fn (string $domain): LimitUsage => $this->usage->registeredDomainUsage($domain, $now),
            $distinct
        );

        return new DashboardReport($domainUsages, $this->usage->newOrdersUsage($accountId, $now));
    }
}
