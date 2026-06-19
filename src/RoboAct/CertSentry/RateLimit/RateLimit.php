<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\RateLimit;

/**
 * The capacity and window of a single rate limit.
 *
 * Let's Encrypt's numbers have changed before and will change again, so they live
 * in data, not in branching logic. A limit is fully described by how many events
 * its bucket holds and over what sliding window, which is all the counting code
 * needs to decide whether one more event fits.
 */
final class RateLimit
{
    /**
     * @param int $capacity the most events the bucket permits within the window
     * @param int $windowSeconds the trailing window the capacity applies over
     */
    public function __construct(
        private readonly RateLimitName $name,
        private readonly int $capacity,
        private readonly int $windowSeconds,
    ) {
    }

    public function name(): RateLimitName
    {
        return $this->name;
    }

    public function capacity(): int
    {
        return $this->capacity;
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * The start of the trailing window measured back from $now, the "since"
     * instant every IssuanceHistory query is bounded by.
     */
    public function windowStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->sub(new \DateInterval('PT' . $this->windowSeconds . 'S'));
    }
}
