#!/usr/bin/env bash
# Smoke test against the local demo site.
# Usage: tools/smoke.sh <plugin-slug> [--no-wc]   activate, exercise, deactivate
#        tools/smoke.sh --mu [--no-wc]            exercise the linked mu-plugins only
# Env:   ERUSOFT_WP_DEMO (site path), ERUSOFT_WP_DEMO_URL (site URL)
# Exit:  0 = clean, 1 = PHP warning/notice/deprecated/fatal/parse from wp-content or HTTP 5xx.
set -euo pipefail

MU=0; NOWC=0; SLUG=""
for a in "$@"; do
  case "$a" in
    --mu) MU=1 ;;
    --no-wc) NOWC=1 ;;
    -*) echo "unknown option: $a" >&2; exit 2 ;;
    *) SLUG="$a" ;;
  esac
done
if [ "$MU" = 0 ] && [ -z "$SLUG" ]; then
  echo "usage: tools/smoke.sh <plugin-slug> [--no-wc] | --mu [--no-wc]" >&2; exit 2
fi
LABEL="${SLUG:-mu-plugins}"; [ "$NOWC" = 1 ] && LABEL="$LABEL (no WooCommerce)"

DEMO="${ERUSOFT_WP_DEMO:-$HOME/code/erusoft-wp-demo}"
URL="${ERUSOFT_WP_DEMO_URL:-http://erusoft-wp-demo.test}"
export WP_CLI_PHP_ARGS='-d error_reporting=24575'
wp() { command wp --path="$DEMO" "$@" 2> >(grep -v 'phar://' >&2); }

LOG="$DEMO/wp-content/debug.log"
WC_WAS_ACTIVE=0
cleanup() {
  local skip=()
  [ "$MU" = 0 ] && skip=(--skip-plugins="$SLUG")
  wp config set WP_DEBUG false --raw >/dev/null || true
  wp config set WP_DEBUG_LOG false --raw >/dev/null || true
  # Deactivate even after a fatal: the plugin is skipped so wp-cli itself can still boot.
  if [ "$MU" = 0 ]; then wp "${skip[@]}" plugin deactivate "$SLUG" >/dev/null 2>&1 || true; fi
  if [ "$WC_WAS_ACTIVE" = 1 ] && [ "$NOWC" = 1 ]; then wp ${skip[@]+"${skip[@]}"} plugin activate woocommerce >/dev/null || true; fi
}
trap cleanup EXIT

wp plugin is-active woocommerce && WC_WAS_ACTIVE=1 || true
rm -f "$LOG"
wp config set WP_DEBUG true --raw >/dev/null
wp config set WP_DEBUG_LOG true --raw >/dev/null
wp config set WP_DEBUG_DISPLAY false --raw >/dev/null

if [ "$NOWC" = 1 ] && [ "$WC_WAS_ACTIVE" = 1 ]; then wp plugin deactivate woocommerce >/dev/null; fi
if [ "$MU" = 0 ]; then wp plugin activate "$SLUG" >/dev/null; fi

# Front-end URLs (shop/cart/checkout only when WooCommerce is loaded; slugs differ per locale).
FAIL=0
PATHS=("/" "/?s=demo")
if wp eval 'exit( function_exists("wc_get_page_permalink") ? 0 : 1 );' >/dev/null 2>&1; then
  for page in shop cart checkout myaccount; do
    full="$(wp eval "echo wc_get_page_permalink('$page');")"
    case "$full" in "$URL"*) PATHS+=("${full#"$URL"}") ;; *) echo "permalink outside $URL: $full"; FAIL=1 ;; esac
  done
fi
for p in "${PATHS[@]}"; do
  code="$(curl -s -o /dev/null -w '%{http_code}' "$URL$p" || echo 000)"
  echo "GET $p -> $code"
  case "$p" in
    */checkout/*|*checkout*) [ "$code" = 200 ] || [ "$code" = 302 ] || FAIL=1 ;;
    *) [ "$code" = 200 ] || FAIL=1 ;;
  esac
done

# Admin: simulate an administrator loading the admin menu and init hooks (no HTTP, no /wp-admin curl).
# WP_ADMIN makes is_admin() true so plugins load their admin code; menu globals are pre-seeded.
ADMIN_OK=0
wp --exec='define("WP_ADMIN", true);' eval 'wp_set_current_user(1); require_once ABSPATH . "wp-admin/includes/admin.php"; $GLOBALS["menu"] = []; $GLOBALS["submenu"] = []; set_current_screen("dashboard"); do_action("admin_menu"); do_action("admin_init");' >/dev/null || ADMIN_OK=1
wp cron event run --due-now >/dev/null 2>&1 || true

if [ "$MU" = 0 ]; then wp --skip-plugins="$SLUG" plugin deactivate "$SLUG" >/dev/null || FAIL=1; fi

if [ "$ADMIN_OK" = 1 ]; then echo "admin hooks failed"; FAIL=1; fi
if [ -s "$LOG" ]; then
  if grep -E 'PHP (Warning|Notice|Deprecated|Fatal error|Parse error|Recoverable fatal error)|WordPress database error' "$LOG" | grep -v 'phar://'; then FAIL=1; fi
fi
if [ "$FAIL" = 1 ]; then echo "SMOKE FAIL: $LABEL"; exit 1; fi
echo "SMOKE OK: $LABEL"
