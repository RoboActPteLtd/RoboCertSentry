<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Preflight;

use RoboAct\CertSentry\Advice\WildcardSuggestion;
use RoboAct\CertSentry\Guard\GuardDecision;
use RoboAct\CertSentry\RateLimit\LimitOutcome;
use RoboAct\CertSentry\RateLimit\RateLimitName;

/**
 * Turns a guard decision and any advice into a render-ready view.
 *
 * This is where machine identities become operator-facing words: the limit enum
 * gains a human label, the boolean verdict gains a headline. Putting that here,
 * once, keeps the wording consistent between the pre-flight panel and anywhere
 * else a decision is shown, and keeps it out of both the guard (which should not
 * know about presentation) and the template (which should not decide wording).
 */
final class PreflightPresenter
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        'CERTIFICATES_PER_REGISTERED_DOMAIN' => 'Certificates per registered domain',
        'DUPLICATE_CERTIFICATE' => 'Duplicate certificate',
        'NEW_ORDERS_PER_ACCOUNT' => 'New orders per account',
        'FAILED_VALIDATIONS' => 'Failed validations',
        'NEW_REGISTRATIONS_PER_IP' => 'New registrations per IP',
    ];

    /**
     * @param list<WildcardSuggestion> $suggestions
     */
    public function present(GuardDecision $decision, array $suggestions): PreflightView
    {
        return new PreflightView(
            $decision->allowed(),
            $decision->isRenewal(),
            $decision->allowed() ? 'Safe to issue' : 'Would be rate-limited',
            array_map(fn (LimitOutcome $o): array => $this->limitRow($o), $decision->outcomes()),
            array_map(fn (WildcardSuggestion $s): array => $this->suggestionRow($s), $suggestions),
            $decision->retryAt()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function limitRow(LimitOutcome $outcome): array
    {
        return [
            'limit' => $outcome->limit(),
            'label' => $this->labelFor($outcome->limit()),
            'bucketKey' => $outcome->bucketKey(),
            'used' => $outcome->used(),
            'capacity' => $outcome->capacity(),
            'remaining' => $outcome->remaining(),
            'applicable' => $outcome->isApplicable(),
            'blocking' => $outcome->isBlocking(),
            'retryAt' => $outcome->retryAt(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function suggestionRow(WildcardSuggestion $suggestion): array
    {
        return [
            'registeredDomain' => $suggestion->registeredDomain(),
            'wildcard' => $suggestion->wildcard(),
            'actionable' => $suggestion->isActionable(),
            'reason' => $suggestion->reason(),
            'bucketsSaved' => $suggestion->bucketsSaved(),
            'hosts' => $suggestion->consolidatableHosts(),
        ];
    }

    private function labelFor(RateLimitName $name): string
    {
        return self::LABELS[$name->name] ?? $name->name;
    }
}
