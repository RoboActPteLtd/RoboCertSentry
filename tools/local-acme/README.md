# Local ACME simulation harness

A fully-local Docker harness for exercising RoboCertSentry against a real Plesk
Obsidian server and a real ACME certificate authority, with no public box, no
public domain, and no Let's Encrypt account.

It exists to verify the two things that cannot be checked from unit tests alone:

1. **Does a certificate binding fire `ssl_web_binding_update` and run the
   extension's reconcile?** (the live ingestion trigger)
2. **What does Plesk's Let's Encrypt extension log on a certificate operation?**
   (the format the backfill importer parses)

## Components

- `plesk` (plesk/plesk) - the Plesk Obsidian server under test.
- `pebble` (letsencrypt/pebble) - a small ACME CA.
- `challtestsrv` (letsencrypt/pebble-challtestsrv) - the DNS Pebble validates
  against; it answers every name with the Plesk container's IP so Pebble's
  validator reaches Plesk's own web server.
- `nginx` (added at runtime) - a spoof front for
  `acme-v02.api.letsencrypt.org`, used only because Plesk's LE extension ignores
  its documented `--server` flag (the code is encrypted and hardcodes the
  production directory). The spoof rewrites `/directory` to Pebble's `/dir`.

## Usage

```sh
cd tools/local-acme
./run-sim.sh            # brings everything up and runs both verifications
docker compose down -v  # full teardown
docker rm -f rcs-acme-sim-nginx
```

The certificates the script generates (`certs/`, `pebble.minica.pem`) are secrets
and are gitignored; the script regenerates them each run.

## Results (Plesk Obsidian 18.0.78.4, June 2026)

### Item 1: live trigger - VERIFIED

Binding a certificate to a domain fires `ssl_web_binding_update`, which runs the
extension's reconcile, which records the issuance in the ledger. Captured with the
ledger wiped first and no manual reconcile:

```
Event Manager handlers registered by the extension:
  Id 6  SSL/TLS certificate on domain assigned/unassigned   -> .../extension --exec robocertsentry reconcile.php
  Id 7  SSL/TLS certificate on Plesk assigned/unassigned     -> (same)
  Id 8  SSL/TLS certificate on mail server assigned/unassigned -> (same)
  Id 9  SSL/TLS certificate on webmail assigned/unassigned   -> (same)

After toggling the cert binding (no manual step), ledger contained:
  key=example.test,www.example.test acct=plesk-default renewal=0
```

This also surfaced and fixed a real bug: `openssl_x509_parse` returns the issuer
with the apostrophe stripped ("Lets Encrypt"), so the original exact-string filter
silently rejected every Let's Encrypt certificate. The fix is the normalised
`RoboAct\CertSentry\Ingest\LetsEncryptIssuer` matcher.

### Item 2: issuance log format - PARTIAL

Confirmed that Plesk's Let's Encrypt extension logs to `/var/log/plesk/panel.log`
with the tag `[extension/letsencrypt]` and domain-bearing messages, for example:

```
[2026-06-20 05:08:42.968] 11146:... ERR [extension/letsencrypt] Domain validation failed for example.test: ...
```

The spoof successfully drove the ACME protocol through account registration and
new-order against the local CA. It then blocked at challenge parsing: Plesk's
bundled `plesk/acme-lib` rejected Pebble's (RFC 8555 compliant but minimal)
challenge object with "Protocol violation: challenge object has missing fields",
before HTTP-01 validation ran. Pebble is intentionally strict and is not
byte-compatible with this client, so a **successful** issuance log line could not
be captured here.

Capturing the success-line format requires a fully LE-compatible CA (Boulder, the
software Let's Encrypt actually runs) or an EC2 box with a real public domain. The
backfill importer's parser is therefore intentionally tolerant (timestamp +
issuance keyword + host) and its success-line patterns remain best-effort pending
that capture. The primary, event-driven path does not depend on log parsing and is
fully verified above.
