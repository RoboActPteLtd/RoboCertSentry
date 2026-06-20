# Changelog

All notable changes to RoboCertSentry are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Reproducible extension build script (tools/build-extension.sh) that stages the
  pure core and PSL data inside plib/ so they survive Plesk's install flattening.
- Event Manager based live trigger: on install the extension registers handlers on
  the SSL/TLS binding events (the closest signal to issuance, since Plesk fires no
  certificate-issuance event) that run the reconcile, removed cleanly on uninstall.
- Repository scaffold: Composer package, PHPUnit configuration, Mise toolchain
  pin, and Plesk extension manifest skeleton.
- Public Suffix List resolver module that maps any hostname to its Let's Encrypt
  "registered domain" bucket, with ICANN and private section support.
- Issuance model: Identifier and IdentifierSet value objects encoding the exact
  identifier set rule (de-duplicated, case-folded, order-independent).
- Issuance history layer: read (IssuanceHistory) and write (IssuanceRecorder)
  contracts, an in-memory reference store, and a durable SQLite-backed store.
- Pre-flight guard: RateLimitPolicy holding the current Let's Encrypt limit
  values as configuration, and CertificateRequestGuard, which checks a planned
  issuance against every relevant limit using sliding-window counts and returns
  a GuardDecision of per-limit outcomes. Correctly applies renewal exemptions.
- Wildcard consolidation advisor that recommends collapsing many per-subdomain
  certificates into one wildcard as the per-registered-domain bucket tightens,
  gated on DNS-01 availability since wildcards require the DNS-01 challenge.
- Source-agnostic ingestion: ObservedCertificate as the common currency and
  CertificateObservationRecorder as the single convergence seam that resolves
  registered domains, classifies renewals, and stays idempotent so the live and
  backfill sources can both feed the ledger without double-counting.
- Live ingestion path: a CertificateInventory port and CertificateSync that
  reconciles the current certificate inventory into the ledger, the workaround for
  Plesk exposing no native certificate-issuance event.
- Backfill ingestion path: a tolerant CertOperationLogParser and LogImporter that
  recover issuances from Plesk's certificate operation logs.
- Reporting: UsageReportBuilder and LimitUsage expose the standing per-registered-
  domain and per-account usage the dashboard shows, counted identically to the guard.
- Pre-flight presentation: PreflightPresenter and PreflightView shape a guard
  decision and any wildcard advice into a render-ready view for the UI.
- Dashboard reporting: DashboardReportBuilder and DashboardReport assemble the
  per-registered-domain and account-wide usage the panel renders, so the Plesk
  controller stays a thin pass-through.
- Plesk Obsidian wrapper under plib/: a composition-root container, Plesk adapters
  for the certificate inventory and DNS-01 capability ports, an IndexController with
  usage and pre-flight views, scheduled reconcile and log-import scripts, and
  install/uninstall registration of both the scheduled jobs and the Event Manager
  handlers. The Plesk-bound layer is thin and delegates to the tested core.
- EventHandlers helper with a unit-tested parser for the `event_handler --list`
  output (fixture captured from a live Plesk Obsidian 18.0.78 box).
- INTEGRATION.md documenting exactly what must be verified on a live Plesk install
  and the configurable extension settings.

### Changed

- Verified the whole Plesk glue against a live Plesk Obsidian 18.0.78 box and
  corrected it to the real SDK contracts:
  - Live trigger now uses the Event Manager (`event_handler` on the SSL/TLS binding
    events) instead of a `Modules_<Id>_EventListener` class, because that interface
    is not present in the runtime.
  - CertificateInventory reads certificates through `pm_Domain::getHostingCertificate`
    and siblings (which do exist, contrary to earlier notes) rather than guessing the
    on-disk cert-store path, and records only Let's Encrypt issued certificates so a
    server's self-signed default never pollutes the ledger.
  - DNS-01 capability is detected from `pm_Domain::getDnsZone()` master/enabled state
    rather than a non-existent `pm_ApiCli::callBin` call.
  - The core and PSL data are packaged inside plib/ and located at runtime via
    `pm_Context::getPlibDir()`, since Plesk discards top-level package directories.

### Fixed

- Packaging bug that would have shipped an extension whose pure core and PSL data
  were stripped on install, leaving it non-functional.
- DNS-01 adapter calling a non-existent `pm_ApiCli` method.
- Event listener referencing an interface absent from the Plesk runtime.
