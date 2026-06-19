<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix;

/**
 * The public suffix portion of a host name, plus where it came from.
 *
 * Pairing the suffix string with its SuffixSection keeps a single fact intact:
 * a name like "blogspot.com" is only a meaningful rate-limit boundary because
 * the PRIVATE section says so. Callers that need to distinguish a real ICANN
 * boundary from a private or guessed one read section() rather than re-deriving
 * it.
 */
final class Suffix implements \Stringable
{
    public function __construct(
        private readonly string $value,
        private readonly SuffixSection $section,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function section(): SuffixSection
    {
        return $this->section;
    }

    /**
     * Whether the suffix came from the published list rather than the implicit
     * "*" fallback. An unknown suffix means the host's TLD is not on the list,
     * so its registered-domain grouping is a best guess, not an authority.
     */
    public function isKnown(): bool
    {
        return $this->section !== SuffixSection::UNKNOWN;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
