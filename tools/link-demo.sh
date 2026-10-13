#!/usr/bin/env bash
# Symlink every themes/*, plugins/* and every mu-plugins/<product>/*.php into the local demo site.
# Env: ERUSOFT_WP_DEMO (site path, default $HOME/code/erusoft-wp-demo;
#      theme demo site: ERUSOFT_WP_DEMO=$HOME/code/erusoft-wp-themes)
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEMO="${ERUSOFT_WP_DEMO:-$HOME/code/erusoft-wp-demo}"
[ -d "$DEMO/wp-content" ] || { echo "demo site not found: $DEMO" >&2; exit 1; }
mkdir -p "$DEMO/wp-content/plugins" "$DEMO/wp-content/mu-plugins" "$DEMO/wp-content/themes"

for d in "$ROOT"/plugins/*/; do
  [ -d "$d" ] || continue
  t="$DEMO/wp-content/plugins/$(basename "$d")"
  if [ -e "$t" ] && [ ! -L "$t" ]; then echo "refusing to overwrite non-symlink: $t" >&2; exit 1; fi
  ln -sfn "${d%/}" "$t"
  echo "plugin   $(basename "$d")"
done
# mu-plugins must be single PHP files at the top level of wp-content/mu-plugins.
for f in "$ROOT"/mu-plugins/*/*.php; do
  [ -f "$f" ] || continue
  t="$DEMO/wp-content/mu-plugins/$(basename "$f")"
  if [ -e "$t" ] && [ ! -L "$t" ]; then echo "refusing to overwrite non-symlink: $t" >&2; exit 1; fi
  ln -sf "$f" "$t"
  echo "mu       $(basename "$f")"
done
for d in "$ROOT"/themes/*/; do
  [ -d "$d" ] || continue
  t="$DEMO/wp-content/themes/$(basename "$d")"
  if [ -e "$t" ] && [ ! -L "$t" ]; then echo "refusing to overwrite non-symlink: $t" >&2; exit 1; fi
  ln -sfn "${d%/}" "$t"
  echo "theme    $(basename "$d")"
done
