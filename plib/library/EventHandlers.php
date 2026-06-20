<?php

declare(strict_types=1);

/**
 * Manages the Plesk Event Manager handlers that drive the live ingestion path.
 *
 * Plesk has no certificate-issuance event, so the extension hooks the SSL/TLS
 * binding events instead and runs a reconcile when one fires. Registration is done
 * through the documented `event_handler` utility via pm_ApiCli (verified on Plesk
 * 18.0.78). Our handlers are tagged by the reconcile command containing the module
 * id, which lets install and uninstall find and remove exactly our own entries
 * without disturbing any the operator added.
 */
final class Modules_Robocertsentry_EventHandlers
{
    private const MARKER = 'robocertsentry';

    /**
     * @param list<string> $events
     */
    public static function reinstall(string $command, array $events): void
    {
        self::removeOurs();
        foreach ($events as $event) {
            self::create($command, $event);
        }
    }

    public static function removeOurs(): void
    {
        foreach (self::ourHandlerIds() as $id) {
            pm_ApiCli::call('event_handler', ['--delete', (string) $id], pm_ApiCli::RESULT_FULL);
        }
    }

    private static function create(string $command, string $event): void
    {
        $result = pm_ApiCli::call(
            'event_handler',
            ['--create', '-command', $command, '-event', $event, '-user', 'root', '-priority', '50'],
            pm_ApiCli::RESULT_FULL
        );

        if (($result['code'] ?? 1) !== 0) {
            throw new RuntimeException(sprintf(
                'Failed to register handler for %s: %s',
                $event,
                trim((string) ($result['stderr'] ?? ''))
            ));
        }
    }

    /**
     * @return list<int>
     */
    private static function ourHandlerIds(): array
    {
        $result = pm_ApiCli::call('event_handler', ['--list'], pm_ApiCli::RESULT_FULL);

        return self::parseOwnIds((string) ($result['stdout'] ?? ''));
    }

    /**
     * Extracts the ids of handlers whose block mentions the module marker. Kept as
     * a pure string operation so the brittle, output-format-dependent part is
     * isolated and easy to re-confirm if Plesk ever changes the listing layout.
     *
     * @return list<int>
     */
    public static function parseOwnIds(string $listing): array
    {
        $ids = [];
        $currentId = null;
        $blockHasMarker = false;

        $flush = static function () use (&$ids, &$currentId, &$blockHasMarker): void {
            if ($currentId !== null && $blockHasMarker) {
                $ids[] = $currentId;
            }
        };

        foreach (preg_split('/\r\n|\r|\n/', $listing) as $line) {
            if (preg_match('/^\s*Id\s+(\d+)/', $line, $m) === 1) {
                $flush();
                $currentId = (int) $m[1];
                $blockHasMarker = false;
                continue;
            }
            if (str_contains($line, self::MARKER)) {
                $blockHasMarker = true;
            }
        }
        $flush();

        return $ids;
    }
}
