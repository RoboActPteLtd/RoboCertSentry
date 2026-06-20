#!/usr/bin/env bash
#
# Builds the installable Plesk extension ZIP.
#
# Plesk keeps meta.xml and the CONTENTS of plib/ (flattened into the module root)
# and discards anything else in the package (verified on Plesk 18.0.78). The pure
# core (src/) and the Public Suffix List data (data/) must therefore be staged
# INSIDE plib/ so they land in the module root, where the autoloader and container
# locate them via pm_Context::getPlibDir().
#
# Usage: tools/build-extension.sh [output.zip]

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
out="${1:-$repo_root/build/robocertsentry.zip}"
stage="$repo_root/build/stage"

rm -rf "$stage" "$out"
mkdir -p "$stage/plib" "$(dirname "$out")"

cp "$repo_root/meta.xml" "$stage/meta.xml"
cp -r "$repo_root/plib/." "$stage/plib/"
cp -r "$repo_root/src" "$stage/plib/src"
cp -r "$repo_root/data" "$stage/plib/data"

# Ship only the runtime PSL data, never any dev cruft.
rm -rf "$stage/plib/vendor" "$stage/plib"/**/*.dist 2>/dev/null || true

( cd "$stage" && zip -rq "$out" . )
echo "Built $out"
