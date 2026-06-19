<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\RateLimit;

/**
 * The Let's Encrypt limits RoboCertSentry models.
 *
 * Naming the limits as a closed set lets every outcome, message, and policy entry
 * refer to the same identity, so a limit cannot be silently misspelled between
 * the place it is configured and the place it is reported.
 */
enum RateLimitName
{
    case CERTIFICATES_PER_REGISTERED_DOMAIN;
    case DUPLICATE_CERTIFICATE;
    case NEW_ORDERS_PER_ACCOUNT;
    case FAILED_VALIDATIONS;
    case NEW_REGISTRATIONS_PER_IP;
}
