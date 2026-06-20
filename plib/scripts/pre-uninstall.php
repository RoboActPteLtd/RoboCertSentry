<?php

declare(strict_types=1);

/**
 * Removes everything this extension registered server-wide, so uninstalling leaves
 * no orphaned Event Manager handlers or scheduler tasks behind. The ledger in the
 * var directory is left for Plesk's own cleanup of the extension's data.
 */

Modules_Robocertsentry_Autoloader::register();

try {
    Modules_Robocertsentry_EventHandlers::removeOurs();
    pm_Log::info('RoboCertSentry event handlers removed');
} catch (Throwable $e) {
    pm_Log::err('RoboCertSentry event handler removal failed: ' . $e->getMessage());
}

try {
    $scheduler = pm_Scheduler::getInstance();
    $ours = ['reconcile.php', 'import-logs.php'];

    foreach ($scheduler->listTasks() as $task) {
        if (in_array($task->getCmd(), $ours, true)) {
            $scheduler->removeTask($task);
        }
    }

    pm_Log::info('RoboCertSentry scheduled tasks removed');
} catch (Throwable $e) {
    pm_Log::err('RoboCertSentry scheduler task removal failed: ' . $e->getMessage());
}
