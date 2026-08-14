#!/bin/bash
# Cach ly 1 site: tao user Linux rieng + pool PHP-FPM rieng.
# Dung: ./isolate-site.sh <domain>
# Muc tieu: site khac KHONG GHI duoc vao site nay (chan dung duong tan cong 13/08).
set -e

# An toan: chi chay tren aaPanel, tranh chay nham tren CyberPanel/panel khac co cau truc khac han
[ -f /www/server/panel/data/default.db ] || { echo "KHONG PHAI aaPanel (thieu /www/server/panel/data/default.db) - AAPANEL_GUARD dung script." >&2; exit 1; }
SITE="$1"
[ -z "$SITE" ] && { echo "Thieu ten site"; exit 1; }
ROOT="/www/wwwroot/$SITE"
[ -d "$ROOT" ] || { echo "Khong thay $ROOT"; exit 1; }

USER="web_$(echo "$SITE" | tr -cd 'a-zA-Z0-9' | cut -c1-24)"
SOCK="/tmp/php-cgi-83-${USER}.sock"
POOLDIR=/www/server/php/83/etc/php-fpm.d
POOL="$POOLDIR/${USER}.conf"
FPMCONF=/www/server/php/83/etc/php-fpm.conf
EXTDIR="/www/server/panel/vhost/nginx/extension/$SITE"
BAK=/root/incident-aqua-155-20260813

echo "=== Site: $SITE | User: $USER | Socket: $SOCK ==="

# 1. Tao user rieng (khong shell, khong login)
if id "$USER" >/dev/null 2>&1; then
  echo "  user $USER da ton tai"
else
  useradd -r -M -d "$ROOT" -s /sbin/nologin "$USER"
  echo "  da tao user $USER"
fi

# 2. Bao dam php-fpm.conf co include thu muc pool
if ! grep -q "^include=$POOLDIR" "$FPMCONF"; then
  cp -p "$FPMCONF" "$BAK/php-fpm.conf.bak-pool-$(date +%Y%m%d%H%M)"
  sed -i "1i include=$POOLDIR/*.conf" "$FPMCONF"
  echo "  da them include pool vao php-fpm.conf"
fi

# 3. Tao pool rieng — ke thua disable_functions + chay bang user rieng
DISFN=$(grep -m1 '^php_admin_value\[disable_functions\]' "$FPMCONF" | sed 's/^[^=]*=[[:space:]]*//')
cat > "$POOL" <<EOF
[$USER]
listen = $SOCK
listen.owner = www
listen.group = www
listen.mode = 0660
user = $USER
group = $USER
pm = ondemand
pm.max_children = 30
pm.process_idle_timeout = 30s
pm.max_requests = 500
php_admin_value[disable_functions] = $DISFN
EOF
echo "  da tao pool $POOL"

# 4. Doi chu so huu file site (giu 755/644 de nginx doc duoc static)
find "$ROOT" -name ".user.ini" -exec chattr -i {} \; 2>/dev/null || true
chown -R "$USER":"$USER" "$ROOT"
find "$ROOT" -name ".user.ini" -exec chattr +i {} \; 2>/dev/null || true
echo "  da chown -R $USER:$USER $ROOT"

# 5. Ep nginx cua site nay dung socket rieng (dat trong extension/ nen song sot khi aaPanel ghi lai vhost)
mkdir -p "$EXTDIR"
cat > "$EXTDIR/00-php-pool.conf" <<EOF
# Pool PHP-FPM rieng cho $SITE (cach ly) - them 2026-08-13
location ~ \.php(/|\$) {
    try_files \$uri \$uri/ /index.php?\$args;
    fastcgi_pass unix:$SOCK;
    fastcgi_index index.php;
    include fastcgi.conf;
    fastcgi_cache_bypass \$skip_cache;
    fastcgi_no_cache \$skip_cache;
    fastcgi_cache WORDPRESS;
    fastcgi_cache_valid 200 1d;
    add_header X-Cache "\$upstream_cache_status From \$host";
    add_header X-Frame-Options SAMEORIGIN;
    add_header X-Content-Type-Options nosniff;
}
EOF
echo "  da tao $EXTDIR/00-php-pool.conf"

echo "=== Kiem tra cau hinh ==="
/www/server/php/83/sbin/php-fpm -t -y "$FPMCONF" 2>&1 | tail -2
nginx -t 2>&1 | tail -2
