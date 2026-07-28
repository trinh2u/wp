# WP Security Kit

Bộ MU-plugin dùng cho các WordPress site trên cùng VPS của bạn.

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

File này có quyền `600`, không nằm trong webroot và không được commit lên Git.

## Chức năng

- Upload Guard: chặn tên file executable qua WordPress và quét PHP/script trong uploads.
- Core Update Guard: khi admin bấm cập nhật WordPress core, tự mở quyền ghi tạm thời, sau đó khóa lại.
- Telegram config loader: đọc bot token/group ID từ `/etc/wp-security-kit/config.conf`.
- `.htaccess`: ngăn thực thi script trong `wp-content/uploads`.
- Cron: chạy `wp-cron` mỗi 5 phút cho từng site vì nhiều site tắt WP-Cron theo traffic.

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

## Kiểm tra

```bash
wp cron event list --allow-root | grep pfhd_upload_guard
stat -c '%a %n' wp-admin wp-includes
```

## Lưu ý

Installer không chạy update core. Admin vẫn bấm Update Now trong Dashboard; Core Update Guard chỉ xử lý permission trong phiên update đó.

Không commit `/etc/wp-security-kit/config.conf`, token Telegram, password SSH hoặc database password vào repository.
