<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Reporting;

use RoboAct\CertSentry\RateLimit\RateLimitName;

/**
 * How full one rate-limit bucket is right now, for display.
 *
 * The guard answers "may this next request proceed"; the usage panel answers the
 * standing question "how close am I" for a bucket with no request in flight. This
 * value object is that standing answer: the count, the ceiling, and the instant a
 * slot next reopens, so the UI can show a meter and a "frees up at" hint without
 * re-deriving any sliding-window arithmetic.
 */
final class LimitUsage
{
    public function __construct(
        private readonly RateLimitName $limit,
        private readonly string $bucketKey,
        private readonly int $used,
        private readonly int $capacity,
        private readonly ?\DateTimeImmutable $nextSlotAt,
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

    public function isAtCapacity(): bool
    {
        return $this->used >= $this->capacity;
    }

    /**
     * The instant the oldest counted event ages out of the window and frees one
     * slot, or null when the bucket holds nothing to age out.
     */
    public function nextSlotAt(): ?\DateTimeImmutable
    {
        return $this->nextSlotAt;
    }
}
