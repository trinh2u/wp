#!/usr/bin/env bash
set -euo pipefail

KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="auto"
WP_CLI_BIN="auto"
DRY_RUN=0
CONFIG_FILE="/etc/wp-security-kit/config.conf"

usage() { echo "Usage: sudo $0 [--root=auto|PATH] [--wp-cli=auto|PATH] [--config=PATH] [--dry-run]"; }
for arg in "$@"; do
  case "$arg" in
    --root=*) ROOT_DIR="${arg#*=}" ;;
    --wp-cli=*) WP_CLI_BIN="${arg#*=}" ;;
    --config=*) CONFIG_FILE="${arg#*=}" ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $arg"; usage; exit 1 ;;
  esac
done

if [[ $EUID -ne 0 ]]; then echo "Run as root: sudo $0"; exit 1; fi
if [[ ! -f "$KIT_DIR/mu-plugins/pfhd-upload-guard.php" || ! -f "$KIT_DIR/mu-plugins/pfhd-core-update.php" ]]; then
  echo "Missing package files under $KIT_DIR/mu-plugins"; exit 1
fi

if [[ "$WP_CLI_BIN" == "auto" ]]; then
  WP_CLI_BIN="$(command -v wp || true)"
  [[ -n "$WP_CLI_BIN" ]] || { for candidate in /usr/local/bin/wp /usr/bin/wp /www/server/wp-cli.phar; do [[ -x "$candidate" ]] && WP_CLI_BIN="$candidate" && break; done; }
fi
[[ -n "$WP_CLI_BIN" && -x "$WP_CLI_BIN" ]] || { echo "WP-CLI not found; use --wp-cli=/absolute/path/to/wp"; exit 1; }

if [[ "$ROOT_DIR" == "auto" ]]; then
  ROOTS=()
  for candidate in /www/wwwroot /home /var/www /srv/www /opt/www; do
    [[ -d "$candidate" ]] && ROOTS+=("$candidate")
  done
else
  ROOTS=("$ROOT_DIR")
fi
mapfile -t SITES < <(for root in "${ROOTS[@]}"; do find "$root" -type f -name wp-config.php -not -path '*/wp-content/*' -not -path '*/backups/*' -printf '%h\n' 2>/dev/null; done | sort -u)
if [[ ${#SITES[@]} -eq 0 ]]; then echo "No WordPress sites found below $ROOT_DIR"; exit 1; fi
echo "Found ${#SITES[@]} WordPress site(s):"; printf '  %s\n' "${SITES[@]}"

if [[ ! -f "$CONFIG_FILE" ]]; then
  if (( DRY_RUN )); then
    echo "DRY-RUN: would prompt for Telegram token/chat ID and create $CONFIG_FILE"
  else
  read -r -s -p "Telegram bot token (hidden): " TG_TOKEN; echo
  read -r -p "Telegram group/chat ID: " TG_CHAT
  [[ -n "$TG_TOKEN" && -n "$TG_CHAT" ]] || { echo "Both values are required"; exit 1; }
  if (( DRY_RUN == 0 )); then
    install -d -m 750 "$(dirname "$CONFIG_FILE")"
    umask 077
    printf 'PFHD_TG_BOT_TOKEN=%q\nPFHD_TG_CHAT_ID=%q\n' "$TG_TOKEN" "$TG_CHAT" > "$CONFIG_FILE"
    chown root:root "$CONFIG_FILE"; chmod 600 "$CONFIG_FILE"
  fi
  fi
else
  echo "Using existing config: $CONFIG_FILE"
fi

for site in "${SITES[@]}"; do
  mu="$site/wp-content/mu-plugins"
  echo "==> $site"
  if (( DRY_RUN )); then echo "    DRY-RUN: would install MU-plugins and cron"; continue; fi
  install -d -m 755 "$mu"
  backup="$site/wp-content/mu-plugins/.wp-security-kit-backup-$(date +%Y%m%d-%H%M%S)"
  install -d -m 700 "$backup"
  for f in 00-pfhd-config.php pfhd-security-lock.php pfhd-upload-guard.php pfhd-core-update.php README.md uploads.htaccess.sample; do
    [[ -f "$mu/$f" ]] && cp -p "$mu/$f" "$backup/$f"
  done
  install -m 644 "$KIT_DIR/mu-plugins/pfhd-upload-guard.php" "$mu/pfhd-upload-guard.php"
  install -m 644 "$KIT_DIR/mu-plugins/pfhd-core-update.php" "$mu/pfhd-core-update.php"
  install -m 644 "$KIT_DIR/mu-plugins/00-pfhd-config.php" "$mu/00-pfhd-config.php"
  install -m 644 "$KIT_DIR/README.md" "$mu/README-WP-SECURITY-KIT.md"
  install -m 644 "$KIT_DIR/templates/uploads.htaccess" "$mu/uploads.htaccess.sample"
  if [[ -f "$site/wp-content/uploads/.htaccess" ]]; then
    cp -p "$site/wp-content/uploads/.htaccess" "$backup/uploads.htaccess.live"
  else
    install -m 644 "$KIT_DIR/templates/uploads.htaccess" "$site/wp-content/uploads/.htaccess"
  fi
  if [[ -x "$WP_CLI_BIN" ]]; then
    (cd "$site" && "$WP_CLI_BIN" cron event run pfhd_upload_guard_scan --allow-root >/dev/null 2>&1 || true)
  fi
  echo "*/5 * * * * cd $site && $WP_CLI_BIN cron event run --due-now --allow-root >> /tmp/wp-security-kit-cron.log 2>&1" > "/etc/cron.d/wp-security-kit-$(echo "$site" | tr '/.' '__')"
  chmod 644 "/etc/cron.d/wp-security-kit-$(echo "$site" | tr '/.' '__')"
done
echo "Installation complete. Config: $CONFIG_FILE"
