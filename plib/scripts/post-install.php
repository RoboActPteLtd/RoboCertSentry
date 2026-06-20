<?php

declare(strict_types=1);

/**
 * Registers the periodic ingestion jobs when the extension is installed.
 *
 * The live event listener (Modules_Robocertsentry_EventListener) is discovered by
 * Plesk automatically and needs no registration; only the scheduled reconciliation
 * and log-backfill jobs are set up here. Existing tasks for the same commands are
 * cleared first so re-running the installer never stacks duplicates.
 *
 * NEEDS LIVE BOX: pm_Scheduler behaviour and the exact schedule semantics can only
 * be verified on a real Plesk install.
 */

Modules_Robocertsentry_Autoloader::register();

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
    pm_Log::err('RoboCertSentry post-install failed: ' . $e->getMessage());
    throw $e;
}
