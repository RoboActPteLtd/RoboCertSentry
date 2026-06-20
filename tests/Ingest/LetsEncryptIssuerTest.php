<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Tests\Ingest;

use PHPUnit\Framework\TestCase;
use RoboAct\CertSentry\Ingest\LetsEncryptIssuer;

/**
 * The matcher must be robust to how a certificate parser renders the issuer. Live
 * testing showed openssl_x509_parse can drop the apostrophe from "Let's Encrypt",
 * so an exact-string match silently rejected every real certificate. Matching on a
 * normalised form is the fix this test pins down.
 */
final class LetsEncryptIssuerTest extends TestCase
{
    public function testMatchesTheCanonicalIssuer(): void
    {
        $this->assertTrue(LetsEncryptIssuer::matches("Let's Encrypt", 'R3'));
    }

    public function testMatchesWhenTheApostropheIsStrippedByTheParser(): void
    {
        $this->assertTrue(LetsEncryptIssuer::matches('Lets Encrypt', 'E1'));
    }

    public function testMatchesIsrgInEitherField(): void
    {
        $this->assertTrue(LetsEncryptIssuer::matches('Internet Security Research Group', 'ISRG Root X1'));
    }

    public function testMatchesRegardlessOfCase(): void
    {
        $this->assertTrue(LetsEncryptIssuer::matches('LETSENCRYPT', ''));
    }

    public function testRejectsOtherAuthorities(): void
    {
        $this->assertFalse(LetsEncryptIssuer::matches('ZeroSSL', 'ZeroSSL RSA Domain Secure Site CA'));
        $this->assertFalse(LetsEncryptIssuer::matches('DigiCert Inc', 'DigiCert Global Root'));
    }

    public function testRejectsASelfSignedDefault(): void
    {
        $this->assertFalse(LetsEncryptIssuer::matches('Plesk', 'Plesk'));
    }
}
