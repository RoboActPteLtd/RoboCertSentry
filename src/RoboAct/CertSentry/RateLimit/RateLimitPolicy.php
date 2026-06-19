<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\RateLimit;

/**
 * The full set of limits in force, keyed by name.
 *
 * Bundling the limits into one policy object means the guard is configured with a
 * single value that can be swapped wholesale, whether for the published Let's
 * Encrypt defaults, a server with a requested override, or the staging
 * environment's looser numbers. The defaults factory is the one place the
 * current published values are written down.
 */
final class RateLimitPolicy
{
    private const WEEK = 7 * 24 * 3600;
    private const THREE_HOURS = 3 * 3600;
    private const HOUR = 3600;

    /**
     * @param array<string, RateLimit> $limits indexed by RateLimitName->name
     */
    private function __construct(
        private readonly array $limits,
    ) {
    }

    /**
     * The published Let's Encrypt production limits, re-verified June 2026.
     * Values are unchanged since the 2025 token-bucket overhaul; modelled here as
     * sliding windows of the equivalent duration.
     */
    public static function letsEncryptDefaults(): self
    {
        return new self([
            RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN->name =>
                new RateLimit(RateLimitName::CERTIFICATES_PER_REGISTERED_DOMAIN, 50, self::WEEK),
            RateLimitName::DUPLICATE_CERTIFICATE->name =>
                new RateLimit(RateLimitName::DUPLICATE_CERTIFICATE, 5, self::WEEK),
            RateLimitName::NEW_ORDERS_PER_ACCOUNT->name =>
                new RateLimit(RateLimitName::NEW_ORDERS_PER_ACCOUNT, 300, self::THREE_HOURS),
            RateLimitName::FAILED_VALIDATIONS->name =>
                new RateLimit(RateLimitName::FAILED_VALIDATIONS, 5, self::HOUR),
            RateLimitName::NEW_REGISTRATIONS_PER_IP->name =>
                new RateLimit(RateLimitName::NEW_REGISTRATIONS_PER_IP, 10, self::THREE_HOURS),
        ]);
    }

    public function limit(RateLimitName $name): RateLimit
    {
        return $this->limits[$name->name];
    }
}
