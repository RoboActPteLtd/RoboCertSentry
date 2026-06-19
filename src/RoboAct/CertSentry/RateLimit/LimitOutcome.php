<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\RateLimit;

/**
 * What one limit says about one planned request against one bucket.
 *
 * The guard returns these rather than throwing on the first block, because a
 * caller wants the whole picture: which buckets are tight, how much headroom is
 * left, and when each would clear. A limit that does not apply (an exempt renewal
 * against the per-registered-domain limit) is still reported, as a non-blocking,
 * non-applicable outcome, so the absence of a block is explained rather than
 * merely implied.
 */
final class LimitOutcome
{
    public function __construct(
        private readonly RateLimitName $limit,
        private readonly string $bucketKey,
        private readonly int $used,
        private readonly int $capacity,
        private readonly bool $applicable,
        private readonly bool $blocking,
        private readonly ?\DateTimeImmutable $retryAt,
    ) {
    }

    public function limit(): RateLimitName
    {
        return $this->limit;
    }

    public function bucketKey(): string
    {
        return $this->bucketKey;
    }

    public function used(): int
    {
        return $this->used;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function remaining(): int
    {
        return max(0, $this->capacity - $this->used);
    }

    /**
     * Whether this limit governs the planned request at all. Exempt renewals
     * produce applicable=false outcomes so the reason is visible, not guessed.
     */
    public function isApplicable(): bool
    {
        return $this->applicable;
    }

    /**
     * Whether this limit alone would cause the request to be rejected right now.
     */
    public function isBlocking(): bool
    {
        return $this->blocking;
    }

    /**
     * The earliest instant the bucket would admit the request, or null when the
     * limit is not blocking.
     */
    public function retryAt(): ?\DateTimeImmutable
    {
        return $this->retryAt;
    }
}
