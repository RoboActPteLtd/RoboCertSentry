<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest;

/**
 * The tally of one reconciliation pass.
 *
 * The live path runs on every relevant Plesk event, so most passes find nothing
 * new. Returning a summary rather than nothing lets the caller log meaningfully
 * ("2 new, 14 already known") and lets a test assert the reconciler did the right
 * amount of work without inspecting the ledger.
 */
final class SyncSummary
{
    public function __construct(
        private readonly int $recordedCount,
        private readonly int $skippedCount,
    ) {
    }

    public function recordedCount(): int
    {
        return $this->recordedCount;
    }

    public function skippedCount(): int
    {
        return $this->skippedCount;
    }

    public function total(): int
    {
        return $this->recordedCount + $this->skippedCount;
    }
}
