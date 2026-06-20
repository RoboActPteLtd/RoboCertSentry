<?php

declare(strict_types=1);

/**
 * Bridges Plesk's class loader to the PSR-4 domain core.
 *
 * Plesk autoloads only its own "Modules_<Id>_*" classes from plib/library; the
 * tested core lives under the RoboAct\CertSentry\ namespace and ships either as a
 * Composer package (plib/vendor) or as bundled source. This registers whichever
 * is present so the thin Plesk classes can lean on the same code the tests cover.
 *
 * NEEDS LIVE BOX: the exact location of the bundled core and Composer vendor dir
 * after the extension is packaged and extracted must be confirmed on a real Plesk
 * install; the candidate paths below are defensive rather than verified.
 */
final class Modules_Robocertsentry_Autoloader
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        $extensionRoot = dirname(__DIR__, 2);

        foreach ([
            $extensionRoot . '/plib/vendor/autoload.php',
            $extensionRoot . '/vendor/autoload.php',
        ] as $composerAutoload) {
            if (is_file($composerAutoload)) {
                require_once $composerAutoload;
                break;
            }
        }

        if (class_exists(\RoboAct\CertSentry\Issuance\IdentifierSet::class)) {
            return;
        }

        $sourceRoot = self::locateSourceRoot($extensionRoot);
        spl_autoload_register(static function (string $class) use ($sourceRoot): void {
            $prefix = 'RoboAct\\CertSentry\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $file = $sourceRoot . '/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        });
    }

    private static function locateSourceRoot(string $extensionRoot): string
    {
        foreach ([
            $extensionRoot . '/src/RoboAct/CertSentry',
            $extensionRoot . '/plib/src/RoboAct/CertSentry',
        ] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        // Fall back to the conventional layout so the failure, if any, is a clear
        // missing-file error rather than a silent no-op autoloader.
        return $extensionRoot . '/src/RoboAct/CertSentry';
    }
}
