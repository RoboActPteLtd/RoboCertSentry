<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance;

use RoboAct\CertSentry\PublicSuffix\DomainName;
use RoboAct\CertSentry\PublicSuffix\Exception\InvalidDomainNameException;

/**
 * A single certificate identifier, which may be a wildcard.
 *
 * Certificate requests carry identifiers, not plain host names: "*.example.com"
 * is a legitimate identifier that DomainName would reject. This type accepts the
 * one wildcard form ACME allows (a single leftmost "*" label) while still
 * delegating the real host validation to DomainName, so the wildcard and its
 * base share one canonicalisation path. Keeping the base host accessible lets
 * the registered-domain resolver treat "*.example.com" and "example.com"
 * identically, which is correct because they share a rate-limit bucket.
 */
final class Identifier implements \Stringable
{
    private function __construct(
        private readonly DomainName $baseDomain,
        private readonly bool $isWildcard,
    ) {
    }

    public static function fromString(string $input): self
    {
        $trimmed = trim($input);
        $isWildcard = false;

        if (str_starts_with($trimmed, '*.')) {
            $isWildcard = true;
            $trimmed = substr($trimmed, 2);
        }

        // A "*" anywhere other than the leftmost label is not a valid identifier;
        // reject it rather than letting it slip into a host label.
        if (str_contains($trimmed, '*')) {
            throw new InvalidDomainNameException(
                sprintf('"%s" places a wildcard outside the leftmost label.', $input)
            );
        }

        return new self(DomainName::fromString($trimmed), $isWildcard);
    }

    public function isWildcard(): bool
    {
        return $this->isWildcard;
    }

    /**
     * The host the identifier is rooted at, with any wildcard label removed.
     */
    public function baseDomain(): DomainName
    {
        return $this->baseDomain;
    }

    /**
     * The canonical identifier string, including the "*." prefix for wildcards.
     * This is the form compared when building the exact-identifier-set bucket.
     */
    public function value(): string
    {
        return $this->isWildcard
            ? '*.' . $this->baseDomain->ascii()
            : $this->baseDomain->ascii();
    }

    public function __toString(): string
    {
        return $this->value();
    }
}
