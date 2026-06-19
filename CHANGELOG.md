# Changelog

All notable changes to RoboCertSentry are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
