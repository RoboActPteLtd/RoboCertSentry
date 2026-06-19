# Public Suffix List data

`public_suffix_list.dat` is the Mozilla Public Suffix List, bundled so that
registered-domain resolution works offline and deterministically.

- Source: https://publicsuffix.org/list/public_suffix_list.dat
- Bundled version: `2026-06-13_21-47-18_UTC`
- Licence: Mozilla Public License v2.0 (the list itself; see file header)

## Why it is vendored

The "certificates per registered domain" rate limit depends on this list. A
network fetch at decision time would make the guard fail open (or slow) exactly
when it is most needed, so the list is checked into the repository and refreshed
deliberately.

## Refreshing

Re-download the file and run the suite; `RealPublicSuffixListTest` will flag any
behavioural drift.

```sh
curl -sSL -o data/public_suffix_list.dat https://publicsuffix.org/list/public_suffix_list.dat
composer test
```

Update the bundled version line above and add a CHANGELOG entry when you do.
