<?php

declare(strict_types=1);

use RoboAct\CertSentry\Advice\Dns01Capability;

/**
 * Tells the advisor whether this server can issue a wildcard at all.
 *
 * A wildcard certificate is only obtainable through the DNS-01 challenge, which in
 * turn needs Plesk to be able to write the _acme-challenge TXT record, either
 * because Plesk hosts the zone or because a DNS-syncing extension does. Plesk
 * exposes no single "DNS-01 ready" flag, so this adapter answers from an explicit
 * operator setting when present and otherwise from whether the Plesk DNS service
 * is enabled.
 *
 * NEEDS LIVE BOX: the DNS-service detection below is the inferred signal; confirm
 * the correct pm_ApiCli/pm_Domain call that proves a domain's zone is writable by
 * Plesk, and prefer that over the coarse server-wide check.
 */
final class Modules_Robocertsentry_Plesk_Dns01Capability implements Dns01Capability
{
    public function isDns01Available(): bool
    {
        $override = pm_Settings::get('dns01_available', '');
        if ($override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return $this->pleskDnsServiceEnabled();
    }

    private function pleskDnsServiceEnabled(): bool
    {
        try {
            $result = pm_ApiCli::callBin('dns', ['--status'], pm_ApiCli::RESULT_FULL);

            return ($result['code'] ?? 1) === 0 && stripos($result['stdout'] ?? '', 'active') !== false;
        } catch (Throwable) {
            // A failure to interrogate DNS is not proof of capability; treat it as
            // "cannot confirm", which keeps wildcard suggestions honestly cautious.
            return false;
        }
    }
}
