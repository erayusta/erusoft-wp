#!/usr/bin/env bash
set -euo pipefail
fail=0
while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { echo "PHP lint failed: $f"; fail=1; }; done < <(git ls-files -z --cached --others --exclude-standard -- '*.php')
exit $fail
