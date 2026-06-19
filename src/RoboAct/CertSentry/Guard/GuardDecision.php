<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Guard;

use RoboAct\CertSentry\RateLimit\LimitOutcome;
use RoboAct\CertSentry\RateLimit\RateLimitName;

/**
 * The verdict on a planned issuance across every relevant limit.
 *
 * This is the decision-object the guard returns instead of throwing: a request
 * being blocked is an expected answer the caller acts on (warn, defer, suggest a
 * wildcard), not an exceptional condition. The decision aggregates the per-limit
 * outcomes and derives the single facts a caller usually wants first, "is it
 * allowed" and "if not, when".
 */
final class GuardDecision
{
    /**
     * @param list<LimitOutcome> $outcomes
     * @param list<string> $registeredDomains
     */
    public function __construct(
        private readonly array $outcomes,
        private readonly bool $isRenewal,
        private readonly array $registeredDomains,
    ) {
    }

    public function allowed(): bool
    {
        return $this->blockingOutcomes() === [];
    }

    /**
     * @return list<LimitOutcome>
     */
    public function outcomes(): array
    {
        return $this->outcomes;
    }

    /**
     * @return list<LimitOutcome>
     */
    public function blockingOutcomes(): array
    {
        return array_values(array_filter(
            $this->outcomes,
            static fn (LimitOutcome $o): bool => $o->isBlocking()
        ));
    }

    /**
     * The first outcome for a named limit, or null if it was not evaluated.
     * Convenience for callers that want one specific limit's headroom.
     */
    public function outcomeFor(RateLimitName $name): ?LimitOutcome
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->limit() === $name) {
                return $outcome;
            }
        }

        return null;
    }

    public function isRenewal(): bool
    {
        return $this->isRenewal;
    }

    /**
     * @return list<string>
     */
    public function registeredDomains(): array
    {
        return $this->registeredDomains;
    }

    /**
     * The instant all blocking limits would have cleared, which is the latest of
     * their individual retry times because every one must admit the request.
     */
    public function retryAt(): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($this->blockingOutcomes() as $outcome) {
            $retryAt = $outcome->retryAt();
            if ($retryAt !== null && ($latest === null || $retryAt > $latest)) {
                $latest = $retryAt;
            }
        }

        return $latest;
    }
}
