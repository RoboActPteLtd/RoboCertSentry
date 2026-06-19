<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance;

use RoboAct\CertSentry\Issuance\Exception\InvalidIdentifierSetException;

/**
 * The exact set of identifiers a certificate covers.
 *
 * Let's Encrypt's duplicate-certificate limit keys on this set "ignoring
 * capitalization and the order of identifiers". This type encodes exactly that
 * rule once: it de-duplicates and sorts on construction and exposes a single
 * canonical key, so two requests for the same names in any order or case map to
 * the same bucket and the same renewal classification.
 */
final class IdentifierSet
{
    /**
     * @param list<Identifier> $identifiers de-duplicated and sorted by canonical value
     */
    private function __construct(
        private readonly array $identifiers,
        private readonly string $canonicalKey,
    ) {
    }

    /**
     * @param list<string> $inputs
     */
    public static function fromStrings(array $inputs): self
    {
        $byValue = [];
        foreach ($inputs as $input) {
            $identifier = Identifier::fromString($input);
            // Keying by canonical value drops case-folded and repeated entries.
            $byValue[$identifier->value()] = $identifier;
        }

        if ($byValue === []) {
            throw new InvalidIdentifierSetException('A certificate must cover at least one identifier.');
        }

        ksort($byValue);
        $identifiers = array_values($byValue);

        return new self($identifiers, implode(',', array_keys($byValue)));
    }

    /**
     * @return list<Identifier>
     */
    public function identifiers(): array
    {
        return $this->identifiers;
    }

    public function canonicalKey(): string
    {
        return $this->canonicalKey;
    }

    public function equals(self $other): bool
    {
        return $this->canonicalKey === $other->canonicalKey;
    }
}
