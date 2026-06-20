<?php

declare(strict_types=1);

/**
 * Backfill / reconciliation job: parse Plesk's certificate operation log and fold
 * any not-yet-recorded issuances into the ledger. Safe to run repeatedly on a
 * schedule because the recorder skips operations it already holds.
 *
 * NEEDS LIVE BOX: confirm the log path and that the operator has enabled the
 * Let's Encrypt request logging the parser depends on (panel.ini [ext-letsencrypt]).
 */

Modules_Robocertsentry_Autoloader::register();

$logPath = (string) pm_Settings::get('panel_log_path', '/var/log/plesk/panel.log');

if (!is_file($logPath) || !is_readable($logPath)) {
    pm_Log::warn(sprintf('RoboCertSentry log import skipped: %s is not readable', $logPath));
    return;
}

$content = file_get_contents($logPath);
if ($content === false) {
    pm_Log::err(sprintf('RoboCertSentry log import failed to read %s', $logPath));
    return;
}

$summary = Modules_Robocertsentry_Container::logImporter()->import(
    $content,
    Modules_Robocertsentry_Container::accountId()
);

pm_Log::info(sprintf(
    'RoboCertSentry log import: %d parsed, %d recorded, %d already known',
    $summary->parsedCount(),
    $summary->recordedCount(),
    $summary->skippedCount()
));
