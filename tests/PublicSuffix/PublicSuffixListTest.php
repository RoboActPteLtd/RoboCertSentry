<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\PublicSuffix;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\PublicSuffix\Exception\PublicSuffixListException;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\Rule;
use RoboAct\CertSentry\PublicSuffix\SuffixSection;

final class PublicSuffixListTest extends TestCase
{
    private const SAMPLE = <<<'PSL'
        // ===BEGIN ICANN DOMAINS===
        com
        co.uk
        uk
        *.ck
        !www.ck

        // a comment that must be ignored
        // ===END ICANN DOMAINS===

        // ===BEGIN PRIVATE DOMAINS===
        blogspot.com
        // ===END PRIVATE DOMAINS===
        PSL;

    public function testIgnoresCommentsAndBlankLines(): void
    {
        $list = PublicSuffixList::fromString(self::SAMPLE);

        // 5 ICANN rules + 1 private rule, comments and blanks excluded.
        $this->assertCount(6, $list->rules());
    }

    public function testParsesPlainRuleLabels(): void
    {
        $rule = $this->ruleFor(PublicSuffixList::fromString(self::SAMPLE), ['co', 'uk']);

        $this->assertFalse($rule->isException());
        $this->assertFalse($rule->isWildcard());
        $this->assertSame(SuffixSection::ICANN, $rule->section());
    }

    public function testParsesWildcardRule(): void
    {
        $rule = $this->ruleFor(PublicSuffixList::fromString(self::SAMPLE), ['*', 'ck']);

        $this->assertTrue($rule->isWildcard());
        $this->assertFalse($rule->isException());
    }

    public function testParsesExceptionRuleWithoutTheBang(): void
    {
        $rule = $this->ruleFor(PublicSuffixList::fromString(self::SAMPLE), ['www', 'ck']);

        $this->assertTrue($rule->isException());
    }

    public function testAssignsPrivateSection(): void
    {
        $rule = $this->ruleFor(PublicSuffixList::fromString(self::SAMPLE), ['blogspot', 'com']);

        $this->assertSame(SuffixSection::PRIVATE, $rule->section());
    }

    public function testConvertsInternationalisedRuleToPunycode(): void
    {
        $list = PublicSuffixList::fromString("// ===BEGIN ICANN DOMAINS===\nmünchen.de\n// ===END ICANN DOMAINS===");

        $this->assertNotNull($this->ruleFor($list, ['xn--mnchen-3ya', 'de']));
    }

    public function testFromFileRejectsMissingPath(): void
    {
        $this->expectException(PublicSuffixListException::class);

        PublicSuffixList::fromFile(__DIR__ . '/does-not-exist.dat');
    }

    /**
     * @param list<string> $labels
     */
    private function ruleFor(PublicSuffixList $list, array $labels): ?Rule
    {
        foreach ($list->rules() as $rule) {
            if ($rule->labels() === $labels) {
                return $rule;
            }
        }

        return null;
    }
}
