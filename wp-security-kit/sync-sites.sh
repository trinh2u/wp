#!/usr/bin/env bash
set -euo pipefail

KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="${1:-auto}"
STATE_DIR="/var/lib/wp-security-kit"
STATE_FILE="$STATE_DIR/installed-sites"
LOCK_FILE="/run/wp-security-kit-sync.lock"

if [[ $EUID -ne 0 ]]; then
  echo "Run as root" >&2
  exit 1
fi
if [[ ! -f "$KIT_DIR/install.sh" ]]; then
  echo "install.sh not found" >&2
  exit 1
fi
if [[ ! -f /etc/wp-security-kit/config.conf ]]; then
  echo "Missing /etc/wp-security-kit/config.conf; run install.sh once first" >&2
  exit 1
fi

mkdir -p "$STATE_DIR"
touch "$STATE_FILE"
exec 9>"$LOCK_FILE"
flock -n 9 || { echo "Another sync is running"; exit 0; }

if [[ "$ROOT_DIR" == "auto" ]]; then
  ROOTS=()
  for candidate in /www/wwwroot /home /var/www /srv/www /opt/www; do
    [[ -d "$candidate" ]] && ROOTS+=("$candidate")
  done
else
  ROOTS=("$ROOT_DIR")
fi

mapfile -t SITES < <(for root in "${ROOTS[@]}"; do
  find "$root" -type f -name wp-config.php -not -path '*/wp-content/*' -not -path '*/backups/*' -printf '%h\n' 2>/dev/null
done | sort -u)

for site in "${SITES[@]}"; do
  grep -Fqx "$site" "$STATE_FILE" && continue
  # Seed state for sites already installed before sync-sites was introduced.
  if [[ -f "$site/wp-content/mu-plugins/00-pfhd-config.php" && \
        -f "$site/wp-content/mu-plugins/pfhd-upload-guard.php" && \
        -f "$site/wp-content/mu-plugins/pfhd-core-update.php" ]]; then
    printf '%s\n' "$site" >> "$STATE_FILE"
    continue
  fi
  echo "[$(date -Is)] New WordPress site: $site"
  if "$KIT_DIR/install.sh" --root="$site"; then
    printf '%s\n' "$site" >> "$STATE_FILE"
    sort -u "$STATE_FILE" -o "$STATE_FILE"
    echo "[$(date -Is)] Installed: $site"
  else
    echo "[$(date -Is)] FAILED: $site" >&2
  fi
done
