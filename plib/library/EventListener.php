<?php

declare(strict_types=1);

/**
 * Drives the live ledger update off the Plesk events nearest to issuance.
 *
 * Plesk has no certificate-issued event, so this subscribes to the events that a
 * certificate operation rides alongside, a domain, subdomain, or DNS change, and
 * on any of them reconciles the current certificate inventory into the ledger. The
 * reconcile is idempotent, so reacting to an unrelated domain change costs only a
 * cheap re-scan, never a phantom record. The event handler must never throw back
 * into Plesk, so failures are logged and swallowed.
 *
 * NEEDS LIVE BOX: confirm the \EventListener interface method names against the
 * installed Plesk SDK and that these object types fire when SSL It! issues a cert.
 */
class Modules_Robocertsentry_EventListener implements EventListener
{
    /**
     * @return list<string>
     */
    public function filterActions()
    {
        return ['domain', 'subdomain', 'domain_dns', 'phosting'];
    }

    /**
     * @param string $objectType
     * @param int $objectId
     * @param string $action
     * @param string $oldValue
     * @param string $newValue
     */
    public function handleEvent($objectType, $objectId, $action, $oldValue, $newValue)
    {
        try {
            Modules_Robocertsentry_Autoloader::register();
            $summary = Modules_Robocertsentry_Container::certificateSync()->reconcile();

            if ($summary->recordedCount() > 0) {
                pm_Log::info(sprintf(
                    'RoboCertSentry recorded %d new issuance(s) after %s event',
                    $summary->recordedCount(),
                    $objectType
                ));
            }
        } catch (Throwable $e) {
            pm_Log::err('RoboCertSentry reconcile failed: ' . $e->getMessage());
        }
    }
}
