# WP Security Kit

MU-plugins and operational scripts for hardening and monitoring WordPress sites on a shared VPS.

## Features

- **Upload Guard** — blocks executable file types uploaded through WordPress, scans `uploads/` for PHP/scripts.
- **Core Update Guard** — temporarily opens write permissions during a WP core update, then locks them back down.
- **Telegram alerts** — all guards report through a single bot/chat configured once.
- **`.htaccess` hardening** — prevents script execution inside `wp-content/uploads`.
- **WP-Cron dispatcher** — hits `wp-cron.php` every 5 minutes per site, since many sites disable traffic-based cron.
- **Site isolation** (aaPanel only) — dedicated Linux user + PHP-FPM pool per site, so a compromised site can't write into another site on the same host. New sites are picked up automatically.
- **Core checksum monitoring** (any panel) — runs `wp core verify-checksums` across every site on a schedule and alerts on unauthorized or modified core files.
- **Root-file / fake-image guard** (any panel) — flags unrecognized files sitting in a site's root directory (blind spot of checksum monitoring, which only covers core WP paths) and detects image-extension files whose real content is a ZIP/PHP/executable payload instead of an actual image.

See **[wp-security-kit/README.md](wp-security-kit/README.md)** (tiếng Việt) for full setup, cron examples, and usage of every script.

## Install

Paste the whole block below into your terminal at once — each line runs in sequence automatically. The
`mv`/`rm` step pulls the `wp-security-kit` folder out of the cloned repo (named `wp` on GitHub) into
`/root/wp-security-kit`, matching every absolute path used in the [Vietnamese docs](wp-security-kit/README.md):

```bash
git clone https://github.com/trinh2u/wp.git
mv wp/wp-security-kit /root/wp-security-kit && rm -rf wp
cd /root/wp-security-kit
chmod +x install.sh
sudo ./install.sh --root=/home
```

Dry run first:

```bash
sudo ./install.sh --root=/home --dry-run
```

The installer asks for a Telegram bot token and chat/group ID once, and saves them to:

```text
/etc/wp-security-kit/config.conf
```

That file is `600`, lives outside the webroot, and is git-ignored.

## Requirements

- WP-CLI available as `wp` (or pass `--wp-cli=/path/to/wp`).
- `file` (libmagic) available — used by `root-file-guard.sh` to read real file content type.
- **Works on any panel or plain LEMP/LAMP**: Upload Guard, Core Update Guard, `sync-sites.sh`,
  `checksum-guard.sh`, `root-file-guard.sh`. They only need WP-CLI and sites under a common webroot
  (`/www/wwwroot`, `/home`, `/var/www`, `/srv/www`, `/opt/www`).
- **aaPanel-only**: `isolate-site.sh` / `auto-isolate.sh`. Not an arbitrary restriction — these two directly
  edit aaPanel's PHP-FPM pool layout (`/www/server/php/*/etc/php-fpm.d`) and nginx vhost structure
  (`/www/server/panel/vhost/nginx/extension/`), and `auto-isolate.sh` reads aaPanel's own SQLite site
  registry to find new sites. Running that logic against a different panel's config format would corrupt it,
  so both scripts detect aaPanel's SQLite database on startup and exit immediately (no changes made) if it's
  missing. Other panels (CyberPanel, Plesk, cPanel) generally isolate each site under its own user by
  default already — the "every site shares one user" problem these scripts fix is specific to aaPanel.

## Check

```bash
wp cron event list --allow-root | grep pfhd_upload_guard
stat -c '%a %n' wp-admin wp-includes
```
