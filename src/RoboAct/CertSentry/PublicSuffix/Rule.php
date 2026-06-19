<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix;

/**
 * A single Public Suffix List rule.
 *
 * The PSL grammar has three rule shapes and they interact, so each rule carries
 * just enough structure for the resolver to apply the precedence order defined
 * by the algorithm at https://publicsuffix.org/list/: exception rules beat
 * wildcards, and among the rest the longest match wins. Labels are stored in
 * ASCII Punycode form (the same canonical form as DomainName) so matching is a
 * plain string comparison rather than an encoding-aware one.
 */
final class Rule
{
    /**
     * @param list<string> $labels rule labels ordered from most to least specific, "!" stripped
     */
    private function __construct(
        private readonly array $labels,
        private readonly bool $isException,
        private readonly bool $isWildcard,
        private readonly SuffixSection $section,
    ) {
    }

    public static function fromLine(string $line, SuffixSection $section): self
    {
        $isException = str_starts_with($line, '!');
        if ($isException) {
            $line = substr($line, 1);
        }

        // Rule labels may themselves be internationalised; canonicalise each to
        // Punycode so they compare equal to a DomainName's ASCII labels.
        $labels = array_map(
            static function (string $label): string {
                if ($label === '*') {
                    return $label;
                }

                $ascii = idn_to_ascii($label, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

                return $ascii === false ? strtolower($label) : strtolower($ascii);
            },
            explode('.', $line)
        );

        $isWildcard = $labels[0] === '*';

        return new self($labels, $isException, $isWildcard, $section);
    }

    /**
     * @return list<string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    public function isException(): bool
    {
        return $this->isException;
    }

    public function isWildcard(): bool
    {
        return $this->isWildcard;
    }

    public function section(): SuffixSection
    {
        return $this->section;
    }

    public function length(): int
    {
        return count($this->labels);
    }

    /**
     * Tests whether this rule's labels align with the right-hand end of a host.
     *
     * Matching anchors at the rightmost label because the public suffix is
     * always a domain's tail; a wildcard label matches any single label in its
     * position, per the PSL specification.
     *
     * @param list<string> $domainLabels host labels ordered from most to least specific
     */
    public function matches(array $domainLabels): bool
    {
        $ruleLength = count($this->labels);
        $domainLength = count($domainLabels);

        if ($ruleLength > $domainLength) {
            return false;
        }

        $offset = $domainLength - $ruleLength;
        foreach ($this->labels as $index => $ruleLabel) {
            if ($ruleLabel === '*') {
                continue;
            }

            if ($ruleLabel !== $domainLabels[$offset + $index]) {
                return false;
            }
        }

        return true;
    }
}
