<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Advice;

/**
 * A recommendation to collapse several subdomain certificates into one wildcard.
 *
 * The suggestion is reported even when it cannot be acted on (no DNS-01), because
 * surfacing the constraint is more useful than silence: the operator learns both
 * that pressure is building and exactly what would unblock the fix. isActionable
 * carries that distinction, and reason carries the human explanation.
 */
final class WildcardSuggestion
{
    /**
     * @param list<string> $consolidatableHosts the subdomain hosts the wildcard would replace
     */
    public function __construct(
        private readonly string $registeredDomain,
        private readonly string $wildcard,
        private readonly array $consolidatableHosts,
        private readonly bool $actionable,
        private readonly string $reason,
    ) {
    }

    public function registeredDomain(): string
    {
        return $this->registeredDomain;
    }

    public function wildcard(): string
    {
        return $this->wildcard;
    }

    /**
     * @return list<string>
     */
    public function consolidatableHosts(): array
    {
        return $this->consolidatableHosts;
    }

    /**
     * How many registered-domain bucket slots the wildcard would reclaim:
     * many separate certificates become a single one.
     */
    public function bucketsSaved(): int
    {
        return max(0, count($this->consolidatableHosts) - 1);
    }

    public function isActionable(): bool
    {
        return $this->actionable;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
