<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Advice;

/**
 * The tuning that decides when a wildcard is worth suggesting.
 *
 * Two judgements are involved and both are deployment-specific, so they are data
 * rather than magic numbers buried in the advisor: how many separate subdomain
 * certificates make consolidation worthwhile, and how close to the per-registered-
 * domain limit counts as "approaching" and therefore worth acting on early.
 */
final class AdvisorThresholds
{
    /**
     * @param int $minimumToConsolidate fewest distinct subdomain certificates that justify a wildcard
     * @param int $headroomThreshold suggest once the per-registered-domain bucket has this many or fewer slots left
     */
    public function __construct(
        private readonly int $minimumToConsolidate = 2,
        private readonly int $headroomThreshold = 20,
    ) {
    }

    public function minimumToConsolidate(): int
    {
        return $this->minimumToConsolidate;
    }

    public function headroomThreshold(): int
    {
        return $this->headroomThreshold;
    }
}
