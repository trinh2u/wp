#!/bin/bash
# Giam sat "wp core verify-checksums" cho moi site WordPress, bao Telegram khi phat hien
# file la / sai checksum trong core WP. Day chinh la cach da tung bat duoc mot loat webshell
# that giau trong core WordPress cua nhieu site tren cung server.
#
# LUU Y: exit code cua "wp core verify-checksums" VAN LA 0 du co Warning (da kiem tra tay) ->
# khong duoc dua vao exit code, phai parse dong bat dau bang "Warning:".
set -u

# An toan: chi chay tren aaPanel, tranh chay nham tren CyberPanel/panel khac co cau truc khac han
[ -f /www/server/panel/data/default.db ] || { echo "KHONG PHAI aaPanel (thieu /www/server/panel/data/default.db) - AAPANEL_GUARD dung script." >&2; exit 1; }
KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG=/var/log/wp-security-kit-checksum.log
CFG=/etc/wp-security-kit/config.conf
[ -f "$CFG" ] && . "$CFG"
WP_CLI=${WP_CLI:-wp}

alert() {
  local msg="$1"
  echo "$(date '+%F %T') $msg" >> "$LOG"
  if [ -n "${PFHD_TG_BOT_TOKEN:-}" ] && [ -n "${PFHD_TG_CHAT_ID:-}" ]; then
    curl -s -m 15 -o /dev/null \
      --data-urlencode "chat_id=$PFHD_TG_CHAT_ID" \
      --data-urlencode "text=[wp-security-kit checksum] $msg" \
      "https://api.telegram.org/bot${PFHD_TG_BOT_TOKEN}/sendMessage" || true
  fi
}

# Tim site WP giong cach sync-sites.sh dang dung (khong lien ket, tu quet lai)
ROOTS=()
for candidate in /www/wwwroot /home /var/www /srv/www /opt/www; do
  [ -d "$candidate" ] && ROOTS+=("$candidate")
done

mapfile -t SITES < <(for root in "${ROOTS[@]}"; do
  find "$root" -maxdepth 1 -type d -not -path "$root" 2>/dev/null
done | sort -u)

bad=0
for site in "${SITES[@]}"; do
  [ -f "$site/wp-config.php" ] || continue
  name=$(basename "$site")
  out=$(cd "$site" && "$WP_CLI" core verify-checksums --allow-root 2>&1)
  warns=$(echo "$out" | grep "^Warning:")
  if [ -n "$warns" ]; then
    bad=$((bad+1))
    n=$(echo "$warns" | wc -l)
    # Cat gon de khong vuot gioi han tin nhan Telegram
    short=$(echo "$warns" | head -15)
    alert "SITE $name: $n canh bao checksum —
$short"
    echo "$(date '+%F %T') CANH BAO $name ($n dong):" >> "$LOG"
    echo "$warns" >> "$LOG"
  fi
done

[ "$bad" -eq 0 ] && echo "$(date '+%F %T') OK - tat ca site verify-checksums sach" >> "$LOG"
exit 0
