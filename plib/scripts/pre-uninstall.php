<?php

declare(strict_types=1);

/**
 * Removes the periodic jobs this extension registered, so uninstalling leaves no
 * orphaned scheduler entries behind. The ledger in the var directory is left for
 * Plesk's own cleanup of the extension's data.
 *
 * NEEDS LIVE BOX: verify against pm_Scheduler on a real install.
 */

Modules_Robocertsentry_Autoloader::register();

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
    pm_Log::err('RoboCertSentry pre-uninstall failed: ' . $e->getMessage());
}
