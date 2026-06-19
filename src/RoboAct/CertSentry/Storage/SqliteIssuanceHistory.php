<?php

declare(strict_types=1);

namespace RoboAct\CertSentry\Storage;

use RoboAct\CertSentry\Issuance\CertificateIssuanceRecord;
use RoboAct\CertSentry\Issuance\IdentifierSet;
use RoboAct\CertSentry\Issuance\IssuanceHistory;
use RoboAct\CertSentry\Issuance\IssuanceRecorder;
use RoboAct\CertSentry\Issuance\ValidationFailureRecord;

/**
 * The durable issuance ledger, backed by SQLite local to the extension.
 *
 * SQLite is deliberate: the guard needs a rolling window of timestamped events
 * that survives restarts, but it is single-host bookkeeping that has no business
 * pulling in a database server. This class is the only place storage details
 * live; by honouring the same IssuanceHistory contract as the in-memory store,
 * it inherits the behaviour proven by the domain tests and adds only persistence.
 *
 * Timestamps are stored as UTC Unix seconds so the window comparison is a plain
 * integer range scan, and the registered domains a certificate touches are kept
 * in a child table so the per-registered-domain query is a simple join.
 */
final class SqliteIssuanceHistory implements IssuanceHistory, IssuanceRecorder
{
    public function __construct(
        private readonly \PDO $pdo,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->migrate();
    }

    public static function inMemory(): self
    {
        return new self(new \PDO('sqlite::memory:'));
    }

    public static function fromFile(string $path): self
    {
        return new self(new \PDO('sqlite:' . $path));
    }

    public function recordIssuance(CertificateIssuanceRecord $record): void
    {
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO issuances (occurred_at, canonical_key, account_id, is_renewal) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([
                $record->occurredAt()->getTimestamp(),
                $record->identifiers()->canonicalKey(),
                $record->accountId(),
                $record->isRenewal() ? 1 : 0,
            ]);

            $issuanceId = (int) $this->pdo->lastInsertId();
            $linkDomain = $this->pdo->prepare(
                'INSERT INTO issuance_domains (issuance_id, registered_domain) VALUES (?, ?)'
            );
            foreach ($record->registeredDomains() as $registeredDomain) {
                $linkDomain->execute([$issuanceId, $registeredDomain]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function recordValidationFailure(ValidationFailureRecord $record): void
    {
        $this->pdo->prepare(
            'INSERT INTO validation_failures (occurred_at, identifier, account_id) VALUES (?, ?, ?)'
        )->execute([
            $record->occurredAt()->getTimestamp(),
            $record->identifier(),
            $record->accountId(),
        ]);
    }

    public function certificatesForRegisteredDomain(string $registeredDomain, \DateTimeImmutable $since): array
    {
        $rows = $this->select(
            'SELECT i.id, i.occurred_at, i.canonical_key, i.account_id, i.is_renewal
               FROM issuances i
               JOIN issuance_domains d ON d.issuance_id = i.id
              WHERE d.registered_domain = ? AND i.occurred_at >= ?',
            [$registeredDomain, $since->getTimestamp()]
        );

        return $this->hydrateAll($rows);
    }

    public function certificatesForIdentifierSet(string $canonicalKey, \DateTimeImmutable $since): array
    {
        $rows = $this->select(
            'SELECT id, occurred_at, canonical_key, account_id, is_renewal
               FROM issuances WHERE canonical_key = ? AND occurred_at >= ?',
            [$canonicalKey, $since->getTimestamp()]
        );

        return $this->hydrateAll($rows);
    }

    public function ordersForAccount(string $accountId, \DateTimeImmutable $since): array
    {
        $rows = $this->select(
            'SELECT id, occurred_at, canonical_key, account_id, is_renewal
               FROM issuances WHERE account_id = ? AND occurred_at >= ?',
            [$accountId, $since->getTimestamp()]
        );

        return $this->hydrateAll($rows);
    }

    public function validationFailures(string $identifier, string $accountId, \DateTimeImmutable $since): array
    {
        $rows = $this->select(
            'SELECT occurred_at FROM validation_failures
              WHERE identifier = ? AND account_id = ? AND occurred_at >= ?',
            [$identifier, $accountId, $since->getTimestamp()]
        );

        return array_map(
            static fn (array $row): ValidationFailureRecord => new ValidationFailureRecord(
                (new \DateTimeImmutable('@' . $row['occurred_at'])),
                $identifier,
                $accountId
            ),
            $rows
        );
    }

    public function hasIssuedIdentifierSet(string $canonicalKey): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM issuances WHERE canonical_key = ? LIMIT 1');
        $statement->execute([$canonicalKey]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<CertificateIssuanceRecord>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): CertificateIssuanceRecord => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CertificateIssuanceRecord
    {
        $domains = $this->select(
            'SELECT registered_domain FROM issuance_domains WHERE issuance_id = ?',
            [$row['id']]
        );

        return new CertificateIssuanceRecord(
            new \DateTimeImmutable('@' . $row['occurred_at']),
            IdentifierSet::fromStrings(explode(',', (string) $row['canonical_key'])),
            (string) $row['account_id'],
            array_map(static fn (array $d): string => (string) $d['registered_domain'], $domains),
            (bool) $row['is_renewal']
        );
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $params): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function migrate(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS issuances (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_at INTEGER NOT NULL,
                canonical_key TEXT NOT NULL,
                account_id TEXT NOT NULL,
                is_renewal INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS issuance_domains (
                issuance_id INTEGER NOT NULL,
                registered_domain TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS validation_failures (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                occurred_at INTEGER NOT NULL,
                identifier TEXT NOT NULL,
                account_id TEXT NOT NULL
            )'
        );
        // The guard always filters by these columns within a time window, so the
        // indexes turn each lookup into a range scan rather than a table scan.
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_issuances_key ON issuances (canonical_key, occurred_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_issuances_account ON issuances (account_id, occurred_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_domains_domain ON issuance_domains (registered_domain)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_failures_lookup ON validation_failures (identifier, account_id, occurred_at)');
    }
}
