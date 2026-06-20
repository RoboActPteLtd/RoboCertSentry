<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Ingest\Log;

/**
 * Recovers certificate operations from Plesk's log text.
 *
 * The backfill source. Plesk's exact panel.log format for Let's Encrypt activity
 * is gated by panel.ini debug settings and is not contractually stable, so this
 * parser does not assume a fixed column layout. It treats a line as a certificate
 * operation only when it carries all three things a real issuance line has: a
 * leading timestamp, an issuance keyword, and at least one host. Anything else is
 * skipped, which keeps an unrelated log line from inventing a phantom issuance.
 *
 * The keyword and host patterns reflect representative panel.log output and are
 * the one place to adjust once the live format is confirmed; the surrounding
 * counting and timezone handling do not depend on them.
 */
final class CertOperationLogParser
{
    /**
     * A leading "YYYY-MM-DD HH:MM:SS" (space or "T" separated) anchors a real log
     * entry; lines without it are continuation or free text and never an event.
     */
    private const TIMESTAMP = '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/';

    /**
     * Issuance is signalled by one of these stems; "renew" alone marks a renewal,
     * which Let's Encrypt accounts for differently from a first issuance.
     */
    private const ISSUANCE_KEYWORD = '/\b(issued|issue|renewed|renew|secured)\b/i';

    private const RENEWAL_KEYWORD = '/\brenew/i';

    /**
     * A host token, optionally wildcarded, requiring at least one dot and a
     * letter TLD so timestamps, IP addresses, and bracketed components do not
     * masquerade as identifiers.
     */
    private const HOST = '/(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}/i';

    public function __construct(
        private readonly \DateTimeZone $logTimezone = new \DateTimeZone('UTC'),
    ) {
    }

    /**
     * @return list<ParsedCertOperation>
     */
    public function parse(string $logContent): array
    {
        $operations = [];
        foreach (preg_split('/\r\n|\r|\n/', $logContent) as $line) {
            $operation = $this->parseLine($line);
            if ($operation !== null) {
                $operations[] = $operation;
            }
        }

        return $operations;
    }

    private function parseLine(string $line): ?ParsedCertOperation
    {
        if (preg_match(self::TIMESTAMP, $line, $stamp) !== 1) {
            return null;
        }

        if (preg_match(self::ISSUANCE_KEYWORD, $line) !== 1) {
            return null;
        }

        $hosts = $this->hostsIn($line);
        if ($hosts === []) {
            return null;
        }

        $occurredAt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $stamp[1] . ' ' . $stamp[2],
            $this->logTimezone
        );

        return new ParsedCertOperation(
            $occurredAt,
            $hosts,
            preg_match(self::RENEWAL_KEYWORD, $line) === 1
        );
    }

    /**
     * The distinct host tokens on the line, in first-seen order.
     *
     * @return list<string>
     */
    private function hostsIn(string $line): array
    {
        if (preg_match_all(self::HOST, $line, $matches) === false) {
            return [];
        }

        $hosts = [];
        foreach ($matches[0] as $host) {
            $hosts[$host] = true;
        }

        return array_keys($hosts);
    }
}
