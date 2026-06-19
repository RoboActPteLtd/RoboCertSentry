<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix;

/**
 * The outcome of resolving a host against the Public Suffix List.
 *
 * This is the value the rate-limit layer actually keys on. It bundles the
 * original host, the public suffix that was found, and the registrable domain
 * (suffix plus one label) so the caller never has to re-run the resolution to
 * recover any one of those parts.
 */
final class RegisteredDomain implements \Stringable
{
    public function __construct(
        private readonly DomainName $domain,
        private readonly Suffix $suffix,
        private readonly string $registrableDomain,
    ) {
    }

    public function domain(): DomainName
    {
        return $this->domain;
    }

    public function suffix(): Suffix
    {
        return $this->suffix;
    }

    /**
     * The registered domain that Let's Encrypt counts against its
     * "certificates per registered domain" limit (for example "example.co.uk").
     */
    public function registrableDomain(): string
    {
        return $this->registrableDomain;
    }

    public function __toString(): string
    {
        return $this->registrableDomain;
    }
}
