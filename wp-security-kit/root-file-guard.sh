#!/bin/bash
# Phat hien 2 dang tan cong ma checksum-guard.sh KHONG bat duoc:
#
# 1) File LA o thu muc GOC site (vd defines.php, shell ten ngau nhien) — day la cach tan cong
#    dat file moi khong thuoc core WP, ma "wp core verify-checksums" CHI kiem tra core (wp-admin/
#    wp-includes + danh sach file root chinh thuc) nen KHONG thay file moi ngoai danh sach do.
#    Truoc day phai "find <site> -maxdepth 1 -name *.php" don tay moi bat duoc — script nay
#    tu dong hoa dung cach do.
# 2) File "ANH" GIA — vd tlogo.bmp thuc chat la ZIP giau payload, nap bang
#    include('zip://tlogo.bmp#tt'). Doi file thanh duoi anh de qua mat moi kiem tra theo TEN/DUOI file
#    (Upload Guard cung chi check duoi). Script nay doc MAGIC BYTE that (qua `file --mime-type`) thay vi
#    tin duoi file.
set -u

LOG=/var/log/wp-security-kit-rootguard.log
CFG=/etc/wp-security-kit/config.conf
[ -f "$CFG" ] && . "$CFG"

alert() {
  local msg="$1"
  echo "$(date '+%F %T') $msg" >> "$LOG"
  if [ -n "${PFHD_TG_BOT_TOKEN:-}" ] && [ -n "${PFHD_TG_CHAT_ID:-}" ]; then
    curl -s -m 15 -o /dev/null \
      --data-urlencode "chat_id=$PFHD_TG_CHAT_ID" \
      --data-urlencode "text=[wp-security-kit root-guard] $msg" \
      "https://api.telegram.org/bot${PFHD_TG_BOT_TOKEN}/sendMessage" || true
  fi
}

# File hop le duoc phep nam o ROOT 1 site WordPress (core WP + file thuong gap khong phai core).
# Tuned tren du lieu THAT cua nhieu site WordPress that de giam bao nham: trang loi mac dinh cua
# panel/webserver (404/502.html), file xac minh Google Search Console, template noi bo, backup
# .htaccess, wordfence-waf.php (bootstrap chinh chu cua plugin Wordfence).
# CO Y KHONG whitelist wp-config.php.bak* — backup lo creds DB trong webroot la rui ro that, van muon
# duoc bao.
WHITELIST_REGEX='^(index\.php|index\.html|license\.txt|readme\.html|wp-activate\.php|wp-blog-header\.php|wp-comments-post\.php|wp-config\.php|wp-config-sample\.php|wp-cron\.php|wp-links-opml\.php|wp-load\.php|wp-login\.php|wp-mail\.php|wp-settings\.php|wp-signup\.php|wp-trackback\.php|xmlrpc\.php|robots\.txt|favicon\.ico|\.htaccess|bk\.htaccess|\.htaccess\.bak.*|\.user\.ini|\.maintenance|\.gitignore|\.gitattributes|sitemap\.xml|sitemap_index\.xml|sitemap-[a-z0-9_-]*\.xml|ads\.txt|app-ads\.txt|humans\.txt|BingSiteAuth\.xml|google[a-f0-9]+\.html|yandex_[a-f0-9]+\.html|SITE_BLUEPRINT\.md|[0-9]{3}\.html|wordfence-waf\.php)$'

# Duoi anh raster co magic byte ro rang — KHONG gom .svg (SVG la text/xml, libmagic hay bao nham)
IMG_EXTS=(-iname "*.jpg" -o -iname "*.jpeg" -o -iname "*.png" -o -iname "*.gif" -o -iname "*.bmp" -o -iname "*.webp" -o -iname "*.ico")

# Tim site WP giong cach checksum-guard.sh / sync-sites.sh dang dung (khong lien ket, tu quet lai)
ROOTS=()
for candidate in /www/wwwroot /home /var/www /srv/www /opt/www; do
  [ -d "$candidate" ] && ROOTS+=("$candidate")
done

mapfile -t SITE_DIRS < <(for r in "${ROOTS[@]}"; do
  find "$r" -maxdepth 1 -type d -not -path "$r" 2>/dev/null
done | sort -u)

bad=0

for d in "${SITE_DIRS[@]}"; do
  site=$(basename "$d")
  [ -f "$d/wp-config.php" ] || continue
  root="${d%/}"

  # --- 1) File la o ROOT (chi maxdepth 1, khong dung vao wp-content/wp-admin/wp-includes) ---
  while IFS= read -r f; do
    base=$(basename "$f")
    echo "$base" | grep -qiE "$WHITELIST_REGEX" && continue
    bad=$((bad+1))
    alert "SITE $site: FILE LA O ROOT (khong thuoc core WP): $base"
    echo "$(date '+%F %T') LA-O-ROOT $site: $f" >> "$LOG"
  done < <(find "$root" -maxdepth 1 -type f 2>/dev/null)

  # --- 2) Anh gia: duoi la anh nhung noi dung that KHONG PHAI anh (vd ZIP/PHP) ---
  while IFS= read -r f; do
    # Bo qua file rong (0 byte) - anh upload loi/chua ghi xong, khong phai dau hieu tan cong
    [ -s "$f" ] || continue
    mime=$(file --mime-type -b "$f" 2>/dev/null)
    rel="${f#"$root"/}"
    case "$mime" in
      image/*) ;;  # that su la anh -> bo qua
      application/zip|application/x-php|text/x-php|application/x-httpd-php|application/x-sh|text/x-shellscript|application/x-executable|application/x-elf|application/x-dosexec)
        # Dung dang tan cong "anh gia": ZIP/PHP/executable doi ten thanh duoi anh -> BAO DONG THAT
        bad=$((bad+1))
        alert "SITE $site: ANH GIA NGUY HIEM - duoi anh nhung mime that la '$mime': $rel"
        echo "$(date '+%F %T') ANH-GIA-NGUY-HIEM $site: $f (mime=$mime)" >> "$LOG"
        ;;
      *)
        # Nghi ngo NHE (html/xml/octet-stream...) - thuong la anh tai loi/hong (vd API nguon anh
        # doi format tra ve trang loi thay vi anh that), KHONG phai dau hieu tan cong.
        # Chi ghi log de tham khao, KHONG gui Telegram — tranh spam hang loat khi co site bi loi tai anh.
        echo "$(date '+%F %T') nghi-ngo-nhe $site: $f (mime=$mime)" >> "$LOG"
        ;;
    esac
  done < <(find "$root" -type f \( "${IMG_EXTS[@]}" \) 2>/dev/null)
done

[ "$bad" -eq 0 ] && echo "$(date '+%F %T') OK - khong phat hien file la o root / anh gia" >> "$LOG"
exit 0
