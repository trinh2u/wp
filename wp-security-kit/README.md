# WP Security Kit

Bộ MU-plugin + script vận hành dùng cho các WordPress site trên cùng VPS của bạn.

## Cài đặt

```bash
cd /root/wp-security-kit
chmod +x install.sh
sudo ./install.sh
```

Chạy thử trước:

```bash
sudo ./install.sh --dry-run
```

Installer hỏi Telegram bot token và group/chat ID một lần, lưu tại:

```text
/etc/wp-security-kit/config.conf
```

File này có quyền `600`, không nằm trong webroot và không được commit lên Git. Các script cách ly/checksum
bên dưới cũng đọc chung file này để gửi cảnh báo Telegram.

## Chức năng

- Upload Guard: chặn tên file executable qua WordPress và quét PHP/script trong uploads.
- Core Update Guard: khi admin bấm cập nhật WordPress core, tự mở quyền ghi tạm thời, sau đó khóa lại.
- Telegram config loader: đọc bot token/group ID từ `/etc/wp-security-kit/config.conf`.
- `.htaccess`: ngăn thực thi script trong `wp-content/uploads`.
- Cron: chạy `wp-cron` mỗi 5 phút cho từng site vì nhiều site tắt WP-Cron theo traffic.
- **Cách ly site** (`isolate-site.sh` + `auto-isolate.sh`): mỗi site 1 user Linux + 1 pool PHP-FPM riêng.
- **Giám sát checksum** (`checksum-guard.sh`): phát hiện file lạ/sai checksum trong core WordPress, báo Telegram.

Installer tự dò các root phổ biến: `/www/wwwroot`, `/home`, `/var/www`, `/srv/www`, `/opt/www`; có thể chỉ định thủ công:

```bash
sudo ./install.sh --root=/www/wwwroot --wp-cli=/usr/local/bin/wp
```

## Tự cài cho site mới

Sau lần cài ban đầu, bật đồng bộ định kỳ:

```bash
sudo chmod +x sync-sites.sh
sudo ./sync-sites.sh
```

Cron mỗi 10 phút:

```cron
*/10 * * * * /root/wp-security-kit/sync-sites.sh >> /var/log/wp-security-kit-sync.log 2>&1
```

Script lưu các site đã cài tại `/var/lib/wp-security-kit/installed-sites`, có lock chống chạy đồng thời và chỉ cài site mới.

## Cách ly site (isolate-site.sh / auto-isolate.sh)

Thêm sau sự cố hack 13/08/2026 trên server 155 (webshell của site A ghi được sang site B vì mọi site chạy
chung 1 user `www` + 1 pool PHP-FPM). Mỗi site cách ly có:

- User Linux riêng `web_<domain>` (`-r -M -s /sbin/nologin`, không login được).
- Pool PHP-FPM riêng, socket riêng, `pm = ondemand`.
- Ownership file site đổi hết sang user đó — site khác không ghi/đọc chéo được nữa.

Chạy tay cho 1 site:

```bash
sudo /root/wp-security-kit/isolate-site.sh <domain>
```

`auto-isolate.sh` quét site nào owner vẫn còn là `www` (nghĩa là site mới tạo, chưa cách ly) rồi tự chạy
`isolate-site.sh` cho site đó — không đụng vào code aaPanel nên không sợ mất khi update panel qua UI. Cron:

```cron
*/10 * * * * /root/wp-security-kit/auto-isolate.sh
```

Log: `/var/log/auto-isolate.log`. Độ trễ tối đa ~10 phút kể từ lúc tạo site trên panel.

⚠️ Sau khi cách ly, sửa file site bằng quyền root (vd sửa qua Claude Code) sẽ tạo file `root:root` khiến
PHP-FPM (chạy bằng `web_*`) không ghi được — phải `chown -R web_<site>:web_<site> <site path>` sau mọi thao
tác root.

## Giám sát checksum (checksum-guard.sh)

Chạy `wp core verify-checksums` cho mọi site, báo Telegram nếu phát hiện file lạ hoặc sai checksum trong core
WordPress — đây chính là cách bắt được 9 webshell thật trong vụ 13/08/2026.

```bash
sudo /root/wp-security-kit/checksum-guard.sh
```

Cron mỗi 6 tiếng:

```cron
0 */6 * * * /root/wp-security-kit/checksum-guard.sh
```

Log: `/var/log/wp-security-kit-checksum.log`. Chỉ gửi Telegram khi có cảnh báo (không spam lúc site sạch).

⚠️ `wp core verify-checksums` **exit code vẫn là 0** dù có `Warning:` — script không dựa vào exit code mà
parse trực tiếp các dòng bắt đầu bằng `Warning:` trong output.

## Kiểm tra

```bash
wp cron event list --allow-root | grep pfhd_upload_guard
stat -c '%a %n' wp-admin wp-includes
sudo /root/wp-security-kit/checksum-guard.sh && tail /var/log/wp-security-kit-checksum.log
sudo /root/wp-security-kit/auto-isolate.sh && tail /var/log/auto-isolate.log
```

## Lưu ý

Installer không chạy update core. Admin vẫn bấm Update Now trong Dashboard; Core Update Guard chỉ xử lý permission trong phiên update đó.

Không commit `/etc/wp-security-kit/config.conf`, token Telegram, password SSH hoặc database password vào repository.
