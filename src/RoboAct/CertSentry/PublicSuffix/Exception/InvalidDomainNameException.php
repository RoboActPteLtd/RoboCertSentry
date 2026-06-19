<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix\Exception;

/**
 * Raised when a string cannot represent a usable host name.
 *
 * Domain validity is a precondition for every downstream rate-limit decision,
 * so we fail loudly at the boundary rather than letting a malformed name slip
 * through and silently land in the wrong rate-limit bucket.
 */
final class InvalidDomainNameException extends \InvalidArgumentException
{
}
