<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix;

use RoboAct\CertSentry\PublicSuffix\Exception\PublicSuffixListException;

/**
 * An in-memory, parsed Public Suffix List.
 *
 * The raw list is a flat text file mixing comments, blank lines and section
 * markers with the rules themselves. Parsing it once into Rule objects keeps
 * that text-wrangling out of the resolver, which should only ever reason about
 * rules. Section markers ("===BEGIN PRIVATE DOMAINS===") are tracked while
 * scanning so every rule is tagged with the section it belongs to.
 */
final class PublicSuffixList
{
    /**
     * @param list<Rule> $rules
     */
    private function __construct(
        private readonly array $rules,
    ) {
    }

    public static function fromString(string $data): self
    {
        $section = SuffixSection::UNKNOWN;
        $rules = [];

        foreach (preg_split('/\R/', $data) ?: [] as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '//')) {
                $section = self::sectionFromComment($line, $section);
                continue;
            }

            // A rule line may carry a trailing inline comment after whitespace.
            $line = preg_split('/\s+/', $line)[0];

            $rules[] = Rule::fromLine($line, $section);
        }

        return new self($rules);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new PublicSuffixListException(
                sprintf('Public Suffix List file "%s" is missing or unreadable.', $path)
            );
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new PublicSuffixListException(
                sprintf('Public Suffix List file "%s" could not be read.', $path)
            );
        }

        return self::fromString($contents);
    }

    /**
     * @return list<Rule>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    private static function sectionFromComment(string $comment, SuffixSection $current): SuffixSection
    {
        if (str_contains($comment, '===BEGIN ICANN DOMAINS===')) {
            return SuffixSection::ICANN;
        }

        if (str_contains($comment, '===BEGIN PRIVATE DOMAINS===')) {
            return SuffixSection::PRIVATE;
        }

        if (str_contains($comment, '===END')) {
            return SuffixSection::UNKNOWN;
        }

        return $current;
    }
}
