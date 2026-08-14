#!/bin/bash
# Tu dong cach ly (user Linux + pool PHP-FPM rieng) cho MOI site moi tao tren aaPanel.
# Chay dinh ky bang cron, KHONG dung code aaPanel -> khong so bi ghi de khi update panel.
# Phat hien site moi: doi chieu bang sites (aaPanel default.db) voi owner thu muc hien tai.
# Site chua cach ly = owner van la "www" (mac dinh aaPanel dat khi tao site).
set -u

# An toan: chi chay tren aaPanel, tranh chay nham tren CyberPanel/panel khac co cau truc khac han
[ -f /www/server/panel/data/default.db ] || { echo "KHONG PHAI aaPanel (thieu /www/server/panel/data/default.db) - AAPANEL_GUARD dung script." >&2; exit 1; }
KIT_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG=/var/log/auto-isolate.log
ISOLATE="$KIT_DIR/isolate-site.sh"
CHANGED=0

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $1" >> "$LOG"; }

[ -x "$ISOLATE" ] || { log "LOI: khong thay $ISOLATE"; exit 1; }

# Danh sach site that su duoc aaPanel quan ly (status=1), tranh dung nham thu muc rac
SITES=$(python3 -c "
import sqlite3
c = sqlite3.connect('/www/server/panel/data/default.db').cursor()
c.execute(\"SELECT name FROM sites WHERE status='1'\")
for r in c.fetchall():
    print(r[0])
")

while IFS= read -r site; do
    [ -z "$site" ] && continue
    root="/www/wwwroot/$site"
    [ -d "$root" ] || continue

    owner=$(stat -c %U "$root" 2>/dev/null)
    # Da cach ly roi (owner web_*) -> bo qua. Owner la "www" (mac dinh) -> site moi, can cach ly.
    if [ "$owner" != "www" ]; then
        continue
    fi

    log "Phat hien site moi chua cach ly: $site (owner=$owner) -> chay isolate-site.sh"
    out=$("$ISOLATE" "$site" 2>&1)
    echo "$out" >> "$LOG"
    CHANGED=1
done <<< "$SITES"

if [ "$CHANGED" = "1" ]; then
    log "Co site moi duoc cach ly -> restart php-fpm-83 + reload nginx"
    systemctl restart php-fpm-83 >> "$LOG" 2>&1
    systemctl reload nginx >> "$LOG" 2>&1
    log "Xong."
fi
