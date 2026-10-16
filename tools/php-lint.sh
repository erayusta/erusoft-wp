#!/usr/bin/env bash
# Lints every tracked or new PHP file. Files of a plugin whose "Requires PHP" header is newer
# than the running PHP are skipped: an older PHP cannot parse them by design.
set -euo pipefail
fail=0
skipped=0
current=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
while IFS= read -r -d '' f; do
	if [[ $f == plugins/*/* ]]; then
		slug=${f#plugins/}
		slug=${slug%%/*}
		header="plugins/$slug/$slug.php"
		need=""
		[[ -f $header ]] && need=$(grep -m1 -i 'Requires PHP:' "$header" | sed -E 's/.*Requires PHP:[[:space:]]*([0-9.]+).*/\1/' || true)
		if [[ -n $need ]] && ! php -r "exit(version_compare('$current', '$need', '>=') ? 0 : 1);"; then
			skipped=$((skipped + 1))
			continue
		fi
	fi
	php -l "$f" >/dev/null || { echo "PHP lint failed: $f"; fail=1; }
done < <(git ls-files -z --cached --others --exclude-standard -- '*.php')
if [[ $skipped -gt 0 ]]; then echo "php-lint: skipped $skipped file(s) of plugins that require a newer PHP than $current"; fi
exit $fail
