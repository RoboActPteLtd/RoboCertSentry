<?php

declare(strict_types=1);

use RoboAct\CertSentry\Guard\PlannedIssuance;
use RoboAct\CertSentry\PublicSuffix\DomainName;
use RoboAct\CertSentry\PublicSuffix\Exception\DomainIsPublicSuffixException;
use RoboAct\CertSentry\PublicSuffix\Exception\InvalidDomainNameException;

/**
 * The extension's UI: the usage dashboard and the pre-flight check.
 *
 * Deliberately thin. Every decision, what counts, whether a request fits, what to
 * advise, comes from the tested core via the container; the controller only turns
 * Plesk request and domain data into core inputs and hands the resulting view
 * models to the templates. Keeping it this lean is what makes the untestable
 * Plesk layer safe despite having no unit tests of its own.
 *
 * NEEDS LIVE BOX: the pm_Controller_Action lifecycle, tab rendering, and
 * pm_Domain enumeration can only be exercised on a real Plesk install.
 */
class IndexController extends pm_Controller_Action
{
    public function init()
    {
        parent::init();
        Modules_Robocertsentry_Autoloader::register();

        $this->view->pageTitle = 'RoboCertSentry';
        $this->view->tabs = [
            ['title' => 'Usage', 'action' => 'dashboard'],
            ['title' => 'Pre-flight check', 'action' => 'preflight'],
        ];
    }

    public function indexAction()
    {
        $this->_forward('dashboard');
    }

    public function dashboardAction()
    {
        $report = Modules_Robocertsentry_Container::dashboardReportBuilder()->build(
            $this->registeredDomains(),
            Modules_Robocertsentry_Container::accountId(),
            $this->now()
        );

        $this->view->report = $report;
    }

    public function preflightAction()
    {
        $this->view->identifiers = '';
        $this->view->preflight = null;

        $raw = trim((string) $this->getRequest()->getParam('identifiers', ''));
        if ($raw === '') {
            return;
        }

        $this->view->identifiers = $raw;

        try {
            $planned = PlannedIssuance::fromStrings(
                $this->splitIdentifiers($raw),
                Modules_Robocertsentry_Container::accountId()
            );
        } catch (Throwable $e) {
            $this->view->error = $e->getMessage();
            return;
        }

        $now = $this->now();
        $decision = Modules_Robocertsentry_Container::guard()->evaluate($planned, $now);
        $suggestions = Modules_Robocertsentry_Container::advisor()->advise($planned, $now);

        $this->view->preflight = Modules_Robocertsentry_Container::preflightPresenter()
            ->present($decision, $suggestions);
    }

    /**
     * The distinct registered domains on this server, the buckets the per-domain
     * limit counts by.
     *
     * @return list<string>
     */
    private function registeredDomains(): array
    {
        $resolver = Modules_Robocertsentry_Container::resolver();
        $domains = [];

        foreach (pm_Domain::getAllDomains() as $domain) {
            try {
                $registrable = $resolver->resolve(DomainName::fromString($domain->getName()))->registrableDomain();
                $domains[$registrable] = true;
            } catch (DomainIsPublicSuffixException | InvalidDomainNameException) {
                continue;
            }
        }

        return array_keys($domains);
    }

    /**
     * @return list<string>
     */
    private function splitIdentifiers(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);

        return $parts === false ? [] : array_values($parts);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
