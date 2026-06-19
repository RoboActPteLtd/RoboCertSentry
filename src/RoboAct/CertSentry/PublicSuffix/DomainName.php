<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix;

use RoboAct\CertSentry\PublicSuffix\Exception\InvalidDomainNameException;

/**
 * A canonicalised host name.
 *
 * Rate-limit bucketing only works if two spellings of the same name collapse to
 * one key. This value object performs that collapse once, at construction, so
 * the rest of the system can compare names byte-for-byte: input is trimmed, the
 * FQDN root dot is dropped, Unicode labels are converted to their ASCII
 * Punycode form, and the whole thing is lower-cased. Storing the canonical form
 * (rather than re-deriving it on demand) guarantees the comparison key never
 * drifts from the value the caller validated.
 */
final class DomainName implements \Stringable
{
    /**
     * @param list<string> $labels canonical labels, ordered from most to least specific
     */
    private function __construct(
        private readonly array $labels,
        private readonly string $ascii,
    ) {
    }

    public static function fromString(string $input): self
    {
        $trimmed = trim($input);

        // A single trailing dot denotes the DNS root and is semantically
        // equivalent to its absence; normalise it away before validation so
        // "example.com." and "example.com" share a bucket.
        if (str_ends_with($trimmed, '.')) {
            $trimmed = substr($trimmed, 0, -1);
        }

        if ($trimmed === '') {
            throw new InvalidDomainNameException('A domain name cannot be empty.');
        }

        $ascii = idn_to_ascii($trimmed, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false || $ascii === '') {
            throw new InvalidDomainNameException(
                sprintf('"%s" is not a valid internationalised domain name.', $input)
            );
        }

        $ascii = strtolower($ascii);
        $labels = explode('.', $ascii);

        foreach ($labels as $label) {
            if ($label === '') {
                throw new InvalidDomainNameException(
                    sprintf('"%s" contains an empty label.', $input)
                );
            }
        }

        return new self($labels, $ascii);
    }

    /**
     * @return list<string> labels ordered from most to least specific (["a", "example", "com"])
     */
    public function labels(): array
    {
        return $this->labels;
    }

    public function ascii(): string
    {
        return $this->ascii;
    }

    public function __toString(): string
    {
        return $this->ascii;
    }
}
