<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Advice;

/**
 * Whether the host can satisfy the DNS-01 challenge.
 *
 * Wildcard certificates are issuable only via DNS-01, so the advisor must know
 * whether a DNS plugin capable of it is configured before recommending a
 * wildcard. This is expressed as a port the Plesk layer implements (by inspecting
 * the installed DNS provider), keeping the advisor itself free of any Plesk
 * dependency and trivially testable with a stub.
 */
interface Dns01Capability
{
    public function isDns01Available(): bool;
}
