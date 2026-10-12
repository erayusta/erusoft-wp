#!/usr/bin/env bash
# Scrubs the built site (site/dist) and the contents of every downloadable ZIP. Exit non-zero on any finding.
# Run `npm run build` first.
set -euo pipefail
cd "$(dirname "$0")/.."
DIST=site/dist
[ -d "$DIST" ] || { echo "scrub-dist: $DIST missing; run npm run build" >&2; exit 2; }
status=0
STRICT=""
[ -n "${SCRUB_TERMS_FILE:-}" ] || [ -f .private/scrub-terms.txt ] && STRICT="--strict"
node tools/scrub.mjs $STRICT --dir "$DIST" --exclude downloads || status=$?
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
for z in "$DIST"/downloads/*.zip; do
  [ -e "$z" ] || continue
  unzip -q "$z" -d "$TMP/$(basename "$z" .zip)"
done
node tools/scrub.mjs $STRICT --dir "$TMP" || status=$?
exit $status
