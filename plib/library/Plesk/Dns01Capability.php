<?php

declare(strict_types=1);

use RoboAct\CertSentry\Advice\Dns01Capability;

/**
 * Tells the advisor whether this server can issue a wildcard at all.
 *
 * A wildcard certificate is only obtainable through the DNS-01 challenge, which
 * needs Plesk to be able to write the _acme-challenge TXT record. That is possible
 * when Plesk is the authoritative (master) DNS server for a zone. This answers
 * from an explicit operator setting when present, and otherwise reports true if
 * any domain on the server has an enabled master DNS zone.
 *
 * Verified on Plesk 18.0.78: pm_Domain::getDnsZone() returns a pm_Dns_Zone whose
 * isEnabled()/isMaster() expose exactly this signal.
 */
final class Modules_Robocertsentry_Plesk_Dns01Capability implements Dns01Capability
{
    public function isDns01Available(): bool
    {
        $override = pm_Settings::get('dns01_available', '');
        if ($override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return $this->anyDomainHasMasterZone();
    }

    private function anyDomainHasMasterZone(): bool
    {
        foreach (pm_Domain::getAllDomains() as $domain) {
            try {
                $zone = $domain->getDnsZone();
                if ($zone->isEnabled() && $zone->isMaster()) {
                    return true;
                }
            } catch (Throwable) {
                // A domain whose zone cannot be read tells us nothing; keep looking
                // rather than mistaking it for proof either way.
                continue;
            }
        }

        return false;
    }
}
