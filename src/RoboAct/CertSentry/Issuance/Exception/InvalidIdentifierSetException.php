<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Issuance\Exception;

/**
 * Raised when a set of certificate identifiers is not usable.
 *
 * Every certificate covers at least one identifier, and the duplicate-certificate
 * limit keys on the exact set. An empty set has no meaningful bucket, so it is a
 * programming error to be surfaced rather than silently tolerated.
 */
final class InvalidIdentifierSetException extends \InvalidArgumentException
{
}
