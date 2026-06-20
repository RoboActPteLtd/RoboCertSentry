<?php

declare(strict_types=1);

use RoboAct\CertSentry\Advice\AdvisorThresholds;
use RoboAct\CertSentry\Advice\WildcardConsolidationAdvisor;
use RoboAct\CertSentry\Guard\CertificateRequestGuard;
use RoboAct\CertSentry\Ingest\CertificateObservationRecorder;
use RoboAct\CertSentry\Ingest\CertificateSync;
use RoboAct\CertSentry\Ingest\Log\CertOperationLogParser;
use RoboAct\CertSentry\Ingest\Log\LogImporter;
use RoboAct\CertSentry\PublicSuffix\PublicSuffixList;
use RoboAct\CertSentry\PublicSuffix\RegisteredDomainResolver;
use RoboAct\CertSentry\RateLimit\RateLimitPolicy;
use RoboAct\CertSentry\Reporting\DashboardReportBuilder;
use RoboAct\CertSentry\Reporting\UsageReportBuilder;
use RoboAct\CertSentry\Storage\SqliteIssuanceHistory;
use RoboAct\CertSentry\Preflight\PreflightPresenter;

/**
 * The one composition root that wires the tested core for the Plesk runtime.
 *
 * Every Plesk-facing class (controllers, event listener, CLI scripts) asks this
 * for ready services rather than constructing them, so the wiring decisions, where
 * the database lives, which policy is in force, which account is ours, are made
 * once. The services themselves are pure and tested; this only supplies their
 * Plesk-shaped dependencies.
 */
final class Modules_Robocertsentry_Container
{
    private static ?SqliteIssuanceHistory $history = null;
    private static ?RegisteredDomainResolver $resolver = null;

    public static function history(): SqliteIssuanceHistory
    {
        Modules_Robocertsentry_Autoloader::register();

        if (self::$history === null) {
            self::$history = SqliteIssuanceHistory::fromFile(self::databasePath());
        }

        return self::$history;
    }

    public static function resolver(): RegisteredDomainResolver
    {
        Modules_Robocertsentry_Autoloader::register();

        if (self::$resolver === null) {
            self::$resolver = new RegisteredDomainResolver(
                PublicSuffixList::fromString(self::publicSuffixListContents())
            );
        }

        return self::$resolver;
    }

    public static function policy(): RateLimitPolicy
    {
        Modules_Robocertsentry_Autoloader::register();

        return RateLimitPolicy::letsEncryptDefaults();
    }

    public static function recorder(): CertificateObservationRecorder
    {
        return new CertificateObservationRecorder(self::resolver(), self::history(), self::history());
    }

    public static function guard(): CertificateRequestGuard
    {
        return new CertificateRequestGuard(self::resolver(), self::history(), self::policy());
    }

    public static function advisor(): WildcardConsolidationAdvisor
    {
        return new WildcardConsolidationAdvisor(
            self::resolver(),
            self::history(),
            self::policy(),
            new Modules_Robocertsentry_Plesk_Dns01Capability(),
            new AdvisorThresholds()
        );
    }

    public static function usageReportBuilder(): UsageReportBuilder
    {
        return new UsageReportBuilder(self::history(), self::policy());
    }

    public static function dashboardReportBuilder(): DashboardReportBuilder
    {
        return new DashboardReportBuilder(self::usageReportBuilder());
    }

    public static function preflightPresenter(): PreflightPresenter
    {
        Modules_Robocertsentry_Autoloader::register();

        return new PreflightPresenter();
    }

    public static function certificateSync(): CertificateSync
    {
        return new CertificateSync(new Modules_Robocertsentry_Plesk_CertificateInventory(), self::recorder());
    }

    public static function logImporter(): LogImporter
    {
        return new LogImporter(new CertOperationLogParser(self::serverTimezone()), self::recorder());
    }

    /**
     * The Let's Encrypt account the server issues under. Plesk uses a single ACME
     * account per server, so an operator-set identifier (defaulting to a stable
     * literal) is enough to bucket the account-scoped limits.
     */
    public static function accountId(): string
    {
        Modules_Robocertsentry_Autoloader::register();

        return (string) pm_Settings::get('le_account_id', 'plesk-default');
    }

    public static function serverTimezone(): \DateTimeZone
    {
        $configured = (string) pm_Settings::get('log_timezone', '');

        return new \DateTimeZone($configured !== '' ? $configured : 'UTC');
    }

    /**
     * NEEDS LIVE BOX: confirm pm_Context::getVarDir() resolves to the expected
     * writable per-extension directory and that PDO SQLite is available there.
     */
    private static function databasePath(): string
    {
        return pm_Context::getVarDir() . 'issuance.sqlite';
    }

    /**
     * NEEDS LIVE BOX: confirm the Public Suffix List data file is packaged at this
     * path once the extension is built and extracted.
     */
    private static function publicSuffixListContents(): string
    {
        $path = dirname(__DIR__, 2) . '/data/public_suffix_list.dat';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException(sprintf('Public Suffix List data not found at %s', $path));
        }

        return $contents;
    }
}
