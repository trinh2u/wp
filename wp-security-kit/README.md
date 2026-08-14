# WP Security Kit

Bộ MU-plugin + script vận hành dùng cho các WordPress site trên cùng VPS của bạn.

## Cài đặt

Dán nguyên khối dưới vào terminal (không cần chạy tay từng dòng — mỗi dòng cách nhau bằng xuống dòng tự
chạy tuần tự khi paste). Toàn bộ tài liệu này giả định kit nằm ở `/root/wp-security-kit`, nên 2 dòng đầu
lấy đúng thư mục con `wp-security-kit/` ra khỏi repo (tên repo trên GitHub là `wp`) rồi bỏ phần còn lại:

```bash
git clone https://github.com/trinh2u/wp.git
mv wp/wp-security-kit /root/wp-security-kit && rm -rf wp
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

File này có quyền `600`, không nằm trong webroot và không được commit lên Git — mọi script trong bộ kit đều đọc chung file này để gửi cảnh báo Telegram.

## Chức năng

- Upload Guard: chặn tên file executable qua WordPress và quét PHP/script trong uploads.
- Core Update Guard: khi admin bấm cập nhật WordPress core, tự mở quyền ghi tạm thời, sau đó khóa lại.
- Telegram config loader: đọc bot token/group ID từ `/etc/wp-security-kit/config.conf`.
- `.htaccess`: ngăn thực thi script trong `wp-content/uploads`.
- Cron: chạy `wp-cron` mỗi 5 phút cho từng site vì nhiều site tắt WP-Cron theo traffic.
- **Cách ly site** (`isolate-site.sh` + `auto-isolate.sh`): mỗi site 1 user Linux + 1 pool PHP-FPM riêng.
- **Giám sát checksum** (`checksum-guard.sh`): phát hiện file lạ/sai checksum trong core WordPress, báo Telegram.
- **Giám sát file root + ảnh giả** (`root-file-guard.sh`): phát hiện file lạ ở thư mục gốc site (checksum-guard
  không quét tới) và file mang đuôi ảnh nhưng nội dung thật là ZIP/PHP/executable.

## Yêu cầu

- **WP-CLI** (`wp`) có sẵn trong PATH, hoặc truyền `--wp-cli=/path/to/wp` cho `install.sh`.
- **`file` (libmagic)** có sẵn — dùng để đọc mime type thật của file trong `root-file-guard.sh`.
- **Chạy được trên mọi panel/server** (aaPanel, CyberPanel, Plesk, cPanel, LEMP/LAMP thường...):
  Upload Guard, Core Update Guard, `sync-sites.sh`, **`checksum-guard.sh`, `root-file-guard.sh`** — cả 5 chỉ
  cần WP-CLI + site nằm dưới 1 trong các thư mục phổ biến (`/www/wwwroot`, `/home`, `/var/www`, `/srv/www`,
  `/opt/www`), không đụng gì đặc thù panel.
- **Chỉ dành riêng cho aaPanel**: `isolate-site.sh` + `auto-isolate.sh`. Lý do KHÔNG phải giới hạn tuỳ tiện —
  2 script này trực tiếp thao túng cấu trúc pool PHP-FPM (`/www/server/php/*/etc/php-fpm.d`) và vhost nginx
  (`/www/server/panel/vhost/nginx/extension/`) đặc thù của riêng aaPanel, và `auto-isolate.sh` đọc thẳng DB
  SQLite site registry của aaPanel để biết site nào mới. Đưa nguyên logic này sang panel khác sẽ ghi sai file
  cấu hình của panel đó. Cả 2 tự kiểm tra `/www/server/panel/data/default.db` (dấu hiệu đặc trưng của
  aaPanel) và **thoát ngay, không làm gì**, nếu không phải aaPanel — an toàn khi lỡ chạy nhầm server khác.
  (Lý do sâu hơn: CyberPanel/Plesk/cPanel vốn đã cô lập user riêng cho từng site theo mặc định — vấn đề
  "mọi site chung 1 user" mà 2 script này giải quyết chỉ tồn tại trên aaPanel.)

Installer tự dò các root phổ biến: `/www/wwwroot`, `/home`, `/var/www`, `/srv/www`, `/opt/www`; có thể chỉ định thủ công:

```bash
sudo ./install.sh --root=/www/wwwroot --wp-cli=/usr/local/bin/wp
```

## Tự cài cho site mới

`install.sh` **tự đăng ký cron** cho việc này — chỉ cần cài 1 lần, không phải làm gì thêm. Nó ghi
`/etc/cron.d/wp-security-kit-sync` (chạy `sync-sites.sh` mỗi 15 phút) ngay trong lúc cài. Site nào mới tạo
sau đó sẽ tự động có mu-plugins bảo vệ trong tối đa 15 phút, không cần chạy tay lại.

Muốn đổi tần suất hoặc chạy tay để kiểm tra ngay:

```bash
sudo /root/wp-security-kit/sync-sites.sh          # chay tay ngay lap tuc
sudo crontab -l 2>/dev/null; cat /etc/cron.d/wp-security-kit-sync   # xem lich hien tai
```

Script lưu các site đã cài tại `/var/lib/wp-security-kit/installed-sites`, có lock chống chạy đồng thời và chỉ cài site mới.

⚠️ **Bug đã fix**: bản cũ ghi entry `/etc/cron.d/wp-security-kit-<site>` (dispatcher wp-cron mỗi 5 phút)
THIẾU cột "user" bắt buộc của định dạng `/etc/cron.d/` (khác `crontab -e` cá nhân không cần cột này) — cron
âm thầm bỏ qua, dispatcher chưa từng chạy lần nào dù file tồn tại từ lâu. Cài lại `install.sh` bản mới sẽ tự
sinh đúng định dạng; site cài từ bản cũ cần sửa tay các file `/etc/cron.d/wp-security-kit-<site>` (thêm `root`
sau lịch chạy, trước lệnh).

## Cách ly site (isolate-site.sh / auto-isolate.sh)

Cách ly từng site bằng user Linux + pool PHP-FPM riêng, vá vấn đề cấu hình mặc định của aaPanel: mọi
site chạy chung 1 user `www` + 1 pool PHP-FPM, nên site A bị chiếm quyền (vd dính webshell) là ghi/đọc chéo
được sang site B ngay. Mỗi site cách ly có:

- User Linux riêng `web_<domain>` (`-r -M -s /sbin/nologin`, không login được).
- Pool PHP-FPM riêng, socket riêng, `pm = ondemand`.
- Ownership file site đổi hết sang user đó — site khác không ghi/đọc chéo được nữa.
- **Tự phát hiện đúng phiên bản PHP của từng site** (đọc từ vhost nginx, không hardcode 1 bản) — server
  aaPanel có nhiều site chạy PHP khác nhau (7.4/8.0/8.1/8.2/8.3...) vẫn cách ly đúng bản đang dùng.

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
WordPress.

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

## Giám sát file root + ảnh giả (root-file-guard.sh)

`checksum-guard.sh` chỉ so khớp **core WordPress** (wp-admin/wp-includes + danh sách file root chính thức) —
nó **không thấy** file hoàn toàn mới nằm ở thư mục gốc site (kiểu file backdoor chèn ngoài core). Và Upload
Guard chỉ chặn theo **đuôi file** — một payload đổi đuôi thành `.jpg`/`.bmp` sẽ lọt qua thẳng. `root-file-guard.sh`
vá 2 lỗ đó:

1. **File lạ ở root** (`find <site> -maxdepth 1`, đối chiếu whitelist file core WP + vài file phổ biến không
   phải core như `robots.txt`, `404.html`, file xác minh Google Search Console...). Tự động hoá đúng cách đã
   dùng để dọn dẹp thủ công trước đây.
2. **Ảnh giả** — quét mọi file đuôi `.jpg/.jpeg/.png/.gif/.bmp/.webp/.ico` trong toàn site, đọc **mime type
   thật** qua `file --mime-type`. Chỉ báo động khi mime là `application/zip`, `text/x-php`, `application/x-sh`
   hoặc tương tự (đúng kiểu tấn công `tlogo.bmp` = ZIP giả ảnh, nạp bằng `include('zip://tlogo.bmp#tt')`).
   Mime khác lạ nhưng vô hại hơn (`text/html`, `text/xml`, `application/octet-stream` — thường là ảnh tải lỗi/
   hỏng, không phải tấn công) chỉ ghi log, **không** gửi Telegram để tránh spam.

```bash
sudo /root/wp-security-kit/root-file-guard.sh
```

Cron mỗi 6 tiếng (lệch 15 phút so với `checksum-guard.sh` để tránh chạy chồng):

```cron
15 */6 * * * /root/wp-security-kit/root-file-guard.sh
```

Log: `/var/log/wp-security-kit-rootguard.log`. Đã test cả 2 chiều: chạy trên 11 site sạch thật (dọn báo nhầm
xuống 0, trừ 2 file `wp-config.php.bak*` — phát hiện thật, backup DB creds còn sót trong webroot, cần xoá thủ
công) và giả lập tấn công thật (ZIP đổi tên `.bmp`, PHP đổi tên `.jpg`) — bắt đúng cả hai.

⚠️ Quét toàn bộ ảnh mỗi site khá nặng — **~4 phút cho 11 site** trong môi trường test. Cân nhắc giãn cron nếu VPS có
hàng chục nghìn ảnh.

## Kiểm tra

```bash
wp cron event list --allow-root | grep pfhd_upload_guard
stat -c '%a %n' wp-admin wp-includes
sudo /root/wp-security-kit/checksum-guard.sh && tail /var/log/wp-security-kit-checksum.log
sudo /root/wp-security-kit/auto-isolate.sh && tail /var/log/auto-isolate.log
sudo /root/wp-security-kit/root-file-guard.sh && tail /var/log/wp-security-kit-rootguard.log
```

## Lưu ý

Installer không chạy update core. Admin vẫn bấm Update Now trong Dashboard; Core Update Guard chỉ xử lý permission trong phiên update đó.

Không commit `/etc/wp-security-kit/config.conf`, token Telegram, password SSH hoặc database password vào repository.
