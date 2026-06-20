<?php

declare(strict_types=1);

/**
 * Bridges Plesk's class loader to the PSR-4 domain core.
 *
 * Plesk autoloads only its own "Modules_<Id>_*" classes from plib/library; the
 * tested core lives under the RoboAct\CertSentry\ namespace. Plesk flattens the
 * packaged plib/ into the module root on install (verified on Plesk 18.0.78), so
 * the core and its data ship inside plib/ and land at the module root, which
 * pm_Context::getPlibDir() reports. This registers a PSR-4 loader rooted there,
 * preferring a Composer vendor autoload if the package was built with one.
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

        $moduleRoot = self::moduleRoot();

        $composerAutoload = $moduleRoot . '/vendor/autoload.php';
        if (is_file($composerAutoload)) {
            require_once $composerAutoload;
        }

        if (class_exists(\RoboAct\CertSentry\Issuance\IdentifierSet::class)) {
            return;
        }

        $sourceRoot = $moduleRoot . '/src/RoboAct/CertSentry';
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

    /**
     * The installed module's root directory. pm_Context::getPlibDir() reports it
     * directly under Plesk; the dirname fallback keeps the loader usable if it is
     * ever exercised before the context is initialised.
     */
    private static function moduleRoot(): string
    {
        if (class_exists('pm_Context')) {
            return rtrim(pm_Context::getPlibDir(), '/');
        }

        return dirname(__DIR__);
    }
}
