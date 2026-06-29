# RoboCertSentry

A Let's Encrypt rate-limit guard, packaged as a Plesk Obsidian extension.

RoboCertSentry tracks certificate issuance activity and answers a single
question before a request is ever sent to the ACME server: *"Will this request
hit a Let's Encrypt rate limit?"* By guarding ahead of time it prevents the
multi-hour or multi-day lockouts that follow once a limit is tripped.

## Modelled limits

Let's Encrypt enforces its limits with a token-bucket algorithm. RoboCertSentry
models each bucket so it can predict exhaustion rather than discover it after a
rejection.

- Certificates per registered domain (50 per 7 days).
- Duplicate certificate per exact set of identifiers (5 per 7 days).
- New orders per account (300 per 3 hours).
- Failed validations per hostname per account (5 per hour).
- New registrations per IP address (10 per 3 hours).

## The "registered domain" concept

Two hostnames such as `a.example.com` and `b.example.com` count against the same
`example.com` bucket. That grouping is governed by the
[Public Suffix List](https://publicsuffix.org/), including its private section,
so tenants of a shared hosting suffix each receive their own bucket. The PSL
resolution lives in its own well-tested module (`src/RoboAct/CertSentry/PublicSuffix`).

## How issuance is recorded

The guard reasons from a ledger of past issuances. Two sources feed that ledger,
and both converge on one tested seam (`Ingest\CertificateObservationRecorder`) so
the rules for bucketing, renewal classification, and de-duplication live in a
single place:

- **Live (primary).** Plesk exposes no certificate-issuance event, so the extension
  registers Plesk Event Manager handlers on the SSL/TLS binding events (the closest
  signal, since a new certificate is then bound to its service) that, on each,
  reconcile the current certificate inventory into the ledger
  (`Ingest\CertificateSync`). The reconcile is idempotent, so re-seeing a
  certificate is expected, not an error.
- **Backfill / reconciliation.** A scheduled job parses Plesk's certificate
  operation log (`Ingest\Log\LogImporter`) to populate history and catch anything
  the live path missed. Re-importing an overlapping window never double-counts.

## Extension UI

- **Usage panel** shows every registered domain's standing against the
  per-registered-domain limit, plus the account-wide new-orders limit, with a
  meter and a "next slot frees" hint.
- **Pre-flight check** takes the identifiers a certificate would cover and reports,
  before the request is sent, whether Let's Encrypt would accept it and when any
  blocking limit clears.
- **Wildcard consolidation** is suggested when many per-subdomain certificates draw
  on a tightening bucket, always carrying the DNS-01 caveat: a wildcard is only
  issuable through the DNS-01 challenge, so the suggestion is marked actionable only
  when a DNS-01 capable provider is available.

## What needs a live Plesk box

The Plesk-specific layer is kept thin and behind interfaces; everything else is
unit-tested. The items requiring verification on a real Plesk install are tracked
in the [project wiki](https://github.com/RoboActPteLtd/RoboCertSentry/wiki).

## Development

```sh
mise install        # provision PHP and Composer
composer install    # install dev dependencies (PHPUnit)
composer test       # run the test suite
```

## Layout

- `src/` Pure, framework-agnostic domain and glue logic (testable without Plesk):
  `PublicSuffix`, `Issuance`, `RateLimit`, `Guard`, `Advice`, `Ingest`, `Reporting`,
  `Preflight`, `Storage`.
- `tests/` PHPUnit suite mirroring `src/`, including an end-to-end ingestion
  pipeline test over the real SQLite ledger.
- `plib/` Plesk Obsidian wrapper: thin adapters (`library/`), controllers and
  `.phtml` views, the event listener, and scheduled `scripts/`. Each Plesk-bound
  file delegates to the tested core and is marked where it needs a live box.
- `meta.xml` Plesk extension manifest.
