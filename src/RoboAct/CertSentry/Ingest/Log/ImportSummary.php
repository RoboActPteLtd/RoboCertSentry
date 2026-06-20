<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest\Log;

/**
 * The outcome of importing one batch of log text.
 *
 * Backfill runs unattended on a schedule, so its result has to be self-explaining
 * in a log line of its own: how many operations the text yielded, how many were
 * new to the ledger, and how many were already known. The gap between parsed and
 * recorded is the reassurance that re-reading the same log does not double-count.
 */
final class ImportSummary
{
    public function __construct(
        private readonly int $parsedCount,
        private readonly int $recordedCount,
        private readonly int $skippedCount,
    ) {
    }

    public function parsedCount(): int
    {
        return $this->parsedCount;
    }

    public function recordedCount(): int
    {
        return $this->recordedCount;
    }

    public function skippedCount(): int
    {
        return $this->skippedCount;
    }
}
