<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Ingest\Log;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Ingest\Log\CertOperationLogParser;

/**
 * The exact panel.log line format Let's Encrypt / SSL It! emit is gated by Plesk
 * config and must be confirmed on a live box, so the parser is deliberately
 * tolerant: it keys on a leading timestamp, an issuance keyword, and the host
 * tokens on the line rather than a rigid column layout. These fixtures encode
 * that contract.
 */
final class CertOperationLogParserTest extends TestCase
{
    private CertOperationLogParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CertOperationLogParser(new \DateTimeZone('UTC'));
    }

    public function testParsesAnIssuanceLineWithMultipleDomains(): void
    {
        $log = '2026-06-19 12:00:03 INFO [extension/letsencrypt] Certificate was issued for example.com, www.example.com';

        $operations = $this->parser->parse($log);

        $this->assertCount(1, $operations);
        $this->assertSame(['example.com', 'www.example.com'], $operations[0]->identifiers());
        $this->assertFalse($operations[0]->isRenewal());
        $this->assertEquals(
            new \DateTimeImmutable('2026-06-19 12:00:03', new \DateTimeZone('UTC')),
            $operations[0]->occurredAt()
        );
    }

    public function testRecognisesRenewals(): void
    {
        $log = '2026-06-19 12:00:03 INFO [extension/letsencrypt] Certificate was renewed for shop.example.com';

        $operations = $this->parser->parse($log);

        $this->assertCount(1, $operations);
        $this->assertTrue($operations[0]->isRenewal());
    }

    public function testCapturesWildcardIdentifiers(): void
    {
        $log = '2026-06-19 12:00:03 INFO [letsencrypt] Certificate issued for *.example.com, example.com';

        $operations = $this->parser->parse($log);

        $this->assertSame(['*.example.com', 'example.com'], $operations[0]->identifiers());
    }

    public function testIgnoresLinesThatAreNotCertificateOperations(): void
    {
        $log = implode("\n", [
            '2026-06-19 11:59:00 INFO [panel] User admin logged in from 10.0.0.2',
            'this line has no timestamp and mentions example.com issued',
            '2026-06-19 12:00:03 INFO [letsencrypt] Certificate issued for a.example.com',
            '',
        ]);

        $operations = $this->parser->parse($log);

        $this->assertCount(1, $operations);
        $this->assertSame(['a.example.com'], $operations[0]->identifiers());
    }

    public function testInterpretsTimestampsInTheConfiguredTimezone(): void
    {
        $parser = new CertOperationLogParser(new \DateTimeZone('Asia/Singapore'));
        $log = '2026-06-19 20:00:00 INFO [letsencrypt] Certificate issued for a.example.com';

        $operations = $parser->parse($log);

        $this->assertSame('2026-06-19T12:00:00+00:00', $operations[0]->occurredAt()->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function testSkipsAnIssuanceKeywordLineWithNoRecognisableHost(): void
    {
        $log = '2026-06-19 12:00:03 INFO [letsencrypt] Certificate issued successfully';

        $this->assertSame([], $this->parser->parse($log));
    }
}
