<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\PublicSuffix;

/**
 * Which section of the Public Suffix List a suffix originates from.
 *
 * The distinction is not cosmetic: Let's Encrypt's "certificates per registered
 * domain" bucket follows the PRIVATE section too, which is precisely why shared
 * hosting suffixes register there. Knowing the section lets callers decide
 * whether a grouping is an official TLD boundary (ICANN), a hosting provider's
 * self-declared boundary (PRIVATE), or an unrecognised name that fell through
 * to the implicit "*" rule (UNKNOWN).
 */
enum SuffixSection
{
    case ICANN;
    case PRIVATE;
    case UNKNOWN;
}
