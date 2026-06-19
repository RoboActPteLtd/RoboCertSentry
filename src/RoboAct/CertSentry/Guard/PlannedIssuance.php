<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Guard;

use RoboAct\CertSentry\Issuance\IdentifierSet;

/**
 * A certificate request that has not been sent yet.
 *
 * This is the subject of the whole guard: the thing we check before it becomes a
 * real order. It carries only what the limits key on, the identifier set and the
 * issuing account, so the guard can answer "would this be rejected" without the
 * request ever touching the ACME server.
 */
final class PlannedIssuance
{
    public function __construct(
        private readonly IdentifierSet $identifiers,
        private readonly string $accountId,
    ) {
    }

    /**
     * @param list<string> $names
     */
    public static function fromStrings(array $names, string $accountId): self
    {
        return new self(IdentifierSet::fromStrings($names), $accountId);
    }

    public function identifiers(): IdentifierSet
    {
        return $this->identifiers;
    }

    public function accountId(): string
    {
        return $this->accountId;
    }
}
