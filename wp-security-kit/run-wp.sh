#!/usr/bin/env bash
set -euo pipefail

WP_CLI_BIN="${WPSK_WP_CLI:-auto}"
if [[ "${1:-}" == --wp-cli=* ]]; then
  WP_CLI_BIN="${1#*=}"
  shift
fi
SITE="${1:-}"
[[ -n "$SITE" && -f "$SITE/wp-config.php" ]] || { echo "Usage: $0 [--wp-cli=PATH] SITE [WP-CLI arguments...]" >&2; exit 2; }
shift

if [[ "$WP_CLI_BIN" == auto ]]; then
  WP_CLI_BIN="$(command -v wp || true)"
  [[ -n "$WP_CLI_BIN" ]] || {
    for candidate in /usr/local/bin/wp /usr/bin/wp /www/server/wp-cli.phar; do
      [[ -x "$candidate" ]] && WP_CLI_BIN="$candidate" && break
    done
  }
fi
[[ -n "$WP_CLI_BIN" && -x "$WP_CLI_BIN" ]] || { echo "WP-CLI not found" >&2; exit 3; }

STATE_DIR="/var/lib/wp-security-kit/php-map"
mkdir -p "$STATE_DIR"
key="$(printf '%s' "$SITE" | sha256sum | awk '{print $1}')"
cache="$STATE_DIR/$key"

if [[ -s "$cache" ]]; then
  PHP_BIN="$(head -1 "$cache")"
  if [[ "$PHP_BIN" == DIRECT ]]; then exec "$WP_CLI_BIN" --path="$SITE" "$@"; fi
  if [[ -x "$PHP_BIN" ]]; then exec "$PHP_BIN" "$WP_CLI_BIN" --path="$SITE" "$@"; fi
fi

if timeout 30 "$WP_CLI_BIN" --path="$SITE" --skip-plugins --skip-themes core is-installed --allow-root >/dev/null 2>&1; then
  printf 'DIRECT\n' > "$cache"
  chmod 600 "$cache"
  exec "$WP_CLI_BIN" --path="$SITE" "$@"
fi

mapfile -t PHP_CANDIDATES < <(compgen -G '/usr/local/lsws/lsphp*/bin/php' | sort -V)
for PHP_BIN in "${PHP_CANDIDATES[@]}"; do
  [[ -x "$PHP_BIN" ]] || continue
  if timeout 30 "$PHP_BIN" "$WP_CLI_BIN" --path="$SITE" --skip-plugins --skip-themes core is-installed --allow-root >/dev/null 2>&1; then
    printf '%s\n' "$PHP_BIN" > "$cache"
    chmod 600 "$cache"
    exec "$PHP_BIN" "$WP_CLI_BIN" --path="$SITE" "$@"
  fi
done

echo "No compatible PHP CLI with WordPress database support found for $SITE" >&2
exit 4
