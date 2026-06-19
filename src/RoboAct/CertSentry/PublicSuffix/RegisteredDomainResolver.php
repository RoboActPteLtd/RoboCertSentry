<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix;

use RoboAct\CertSentry\PublicSuffix\Exception\DomainIsPublicSuffixException;

/**
 * Resolves a host to its registered domain using the Public Suffix List.
 *
 * This is the one genuinely fiddly piece of the whole guard: getting the
 * registered-domain boundary wrong silently mis-buckets every rate-limit count.
 * It therefore implements the published PSL algorithm faithfully rather than
 * with shortcuts, including the precedence order (exception beats wildcard,
 * longest match otherwise) and the implicit "*" fallback for unknown TLDs.
 *
 * The private-section toggle exists because Let's Encrypt follows the PRIVATE
 * section when counting certificates per registered domain. Including it (the
 * default) matches Boulder's behaviour so RoboCertSentry's predictions line up
 * with the server's actual accounting; excluding it answers the different
 * question of "what is the ICANN-level registrable domain".
 */
final class RegisteredDomainResolver
{
    public function __construct(
        private readonly PublicSuffixList $list,
        private readonly bool $includePrivate = true,
    ) {
    }

    public function resolve(DomainName $domain): RegisteredDomain
    {
        $labels = $domain->labels();
        $prevailing = $this->selectPrevailingRule($labels);

        if ($prevailing === null) {
            // No rule matched, so the implicit "*" rule applies: the suffix is
            // the single rightmost label and the grouping is a best guess.
            $suffixLength = 1;
            $section = SuffixSection::UNKNOWN;
        } elseif ($prevailing->isException()) {
            // An exception rule's suffix is the rule with its leftmost label
            // removed, which is what makes the excepted name registrable.
            $suffixLength = $prevailing->length() - 1;
            $section = $prevailing->section();
        } else {
            $suffixLength = $prevailing->length();
            $section = $prevailing->section();
        }

        $domainLength = count($labels);

        if ($domainLength <= $suffixLength) {
            throw new DomainIsPublicSuffixException(
                sprintf('"%s" is a public suffix and has no registrable domain.', $domain->ascii())
            );
        }

        $suffixValue = implode('.', array_slice($labels, $domainLength - $suffixLength));
        $registrable = implode('.', array_slice($labels, $domainLength - $suffixLength - 1));

        return new RegisteredDomain($domain, new Suffix($suffixValue, $section), $registrable);
    }

    /**
     * Picks the rule that governs a host, honouring PSL precedence.
     *
     * @param list<string> $labels
     */
    private function selectPrevailingRule(array $labels): ?Rule
    {
        $exception = null;
        $normal = null;

        foreach ($this->list->rules() as $rule) {
            if (!$this->includePrivate && $rule->section() === SuffixSection::PRIVATE) {
                continue;
            }

            if (!$rule->matches($labels)) {
                continue;
            }

            if ($rule->isException()) {
                if ($exception === null || $rule->length() > $exception->length()) {
                    $exception = $rule;
                }
                continue;
            }

            if ($normal === null || $rule->length() > $normal->length()) {
                $normal = $rule;
            }
        }

        // Exception rules outrank every other match by specification.
        return $exception ?? $normal;
    }
}
