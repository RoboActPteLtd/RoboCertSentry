<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix\Exception;

/**
 * Raised when the Public Suffix List cannot be loaded or parsed.
 *
 * A missing or corrupt list would silently degrade every registered-domain
 * decision to the "*" fallback, quietly mis-bucketing real domains. We surface
 * the failure instead so a broken deployment is caught at start-up.
 */
final class PublicSuffixListException extends \RuntimeException
{
}
