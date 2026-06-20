<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Preflight;

/**
 * The pre-flight verdict shaped for rendering, nothing more.
 *
 * The controller hands this straight to a template, so it exposes only finished
 * answers: a headline, plain rows, and flags. Keeping the rows as simple arrays
 * is deliberate, it matches how a Plesk .phtml view consumes data and keeps the
 * template free of any reach back into the guard's objects.
 */
final class PreflightView
{
    /**
     * @param list<array<string, mixed>> $limitRows
     * @param list<array<string, mixed>> $suggestionRows
     */
    public function __construct(
        private readonly bool $allowed,
        private readonly bool $isRenewal,
        private readonly string $statusHeadline,
        private readonly array $limitRows,
        private readonly array $suggestionRows,
        private readonly ?\DateTimeImmutable $retryAt,
    ) {
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function isRenewal(): bool
    {
        return $this->isRenewal;
    }

    public function statusHeadline(): string
    {
        return $this->statusHeadline;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function limitRows(): array
    {
        return $this->limitRows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function suggestionRows(): array
    {
        return $this->suggestionRows;
    }

    public function retryAt(): ?\DateTimeImmutable
    {
        return $this->retryAt;
    }
}
