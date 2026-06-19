<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix\Exception;

/**
 * Raised when a host is itself a public suffix and has no registrable domain.
 *
 * "co.uk" or "com" cannot anchor a certificate, so there is no registered-domain
 * bucket to charge against. This is an exceptional, unrecoverable input for the
 * resolver rather than a normal answer, so it is signalled rather than returned.
 */
final class DomainIsPublicSuffixException extends \RuntimeException
{
}
