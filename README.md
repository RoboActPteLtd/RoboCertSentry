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

## Development

```sh
mise install        # provision PHP and Composer
composer install    # install dev dependencies (PHPUnit)
composer test       # run the test suite
```

## Layout

- `src/` Pure, framework-agnostic domain logic (testable without Plesk).
- `tests/` PHPUnit suite mirroring `src/`.
- `plib/`, `htdocs/`, `meta.xml` Plesk Obsidian extension wrapper.
