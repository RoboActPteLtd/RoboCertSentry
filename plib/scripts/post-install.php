<?php

declare(strict_types=1);

/**
 * Wires the two ingestion paths into Plesk when the extension is installed.
 *
 * Live path: Plesk fires no certificate-issuance event, but it does fire SSL/TLS
 * binding events (verified on Plesk 18.0.78 via `plesk bin event_handler
 * --list-events`). A freshly issued certificate is then bound to its service, so
 * those binding events are the closest live signal. We register Event Manager
 * handlers on them that run the reconcile, which is idempotent.
 *
 * Backfill path: scheduled reconcile and log-import jobs via pm_Scheduler.
 *
 * Both registrations are made idempotent so re-running the installer never stacks
 * duplicates.
 */

Modules_Robocertsentry_Autoloader::register();

const RCS_RECONCILE_CMD = '/usr/local/psa/bin/extension --exec robocertsentry reconcile.php';
const RCS_SSL_EVENTS = [
    'ssl_web_binding_update',
    'ssl_panel_binding_update',
    'ssl_mail_binding_update',
    'ssl_web_mail_binding_update',
];

try {
    Modules_Robocertsentry_EventHandlers::reinstall(RCS_RECONCILE_CMD, RCS_SSL_EVENTS);
    pm_Log::info('RoboCertSentry event handlers installed');
} catch (Throwable $e) {
    // A missing Event Manager must not abort install; the scheduled reconcile
    // below still keeps the ledger current, just less promptly.
    pm_Log::err('RoboCertSentry event handler registration failed: ' . $e->getMessage());
}

try {
    $scheduler = pm_Scheduler::getInstance();

    $wanted = [
        'reconcile.php' => ['minute' => '*/30', 'hour' => '*', 'dom' => '*', 'month' => '*', 'dow' => '*'],
        'import-logs.php' => ['minute' => '15', 'hour' => '3', 'dom' => '*', 'month' => '*', 'dow' => '*'],
    ];

    foreach ($scheduler->listTasks() as $existing) {
        if (array_key_exists($existing->getCmd(), $wanted)) {
            $scheduler->removeTask($existing);
        }
    }

    foreach ($wanted as $cmd => $schedule) {
        $task = new pm_Scheduler_Task();
        $task->setCmd($cmd);
        $task->setSchedule($schedule);
        $scheduler->putTask($task);
    }

    pm_Log::info('RoboCertSentry scheduled tasks installed');
} catch (Throwable $e) {
    pm_Log::err('RoboCertSentry scheduler registration failed: ' . $e->getMessage());
    throw $e;
}
