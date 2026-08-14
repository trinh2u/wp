# WP Security Kit

MU-plugins and operational scripts for hardening and monitoring WordPress sites on a shared VPS.

## Features

- **Upload Guard** — blocks executable file types uploaded through WordPress, scans `uploads/` for PHP/scripts.
- **Core Update Guard** — temporarily opens write permissions during a WP core update, then locks them back down.
- **Telegram alerts** — all guards report through a single bot/chat configured once.
- **`.htaccess` hardening** — prevents script execution inside `wp-content/uploads`.
- **WP-Cron dispatcher** — hits `wp-cron.php` every 5 minutes per site, since many sites disable traffic-based cron.
- **Site isolation** (aaPanel only) — dedicated Linux user + PHP-FPM pool per site, so a compromised site can't write into another site on the same host. New sites are picked up automatically.
- **Core checksum monitoring** — runs `wp core verify-checksums` across every site on a schedule and alerts on unauthorized or modified core files.
- **Root-file / fake-image guard** — flags unrecognized files sitting in a site's root directory (blind spot of checksum monitoring, which only covers core WP paths) and detects image-extension files whose real content is a ZIP/PHP/executable payload instead of an actual image.

See **[wp-security-kit/README.md](wp-security-kit/README.md)** (tiếng Việt) for full setup, cron examples, and usage of every script.

## Install

```bash
git clone https://github.com/trinh2u/wp.git
cd wp/wp-security-kit
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
- Site isolation, checksum monitoring, and the root-file guard (`isolate-site.sh`, `auto-isolate.sh`, `checksum-guard.sh`, `root-file-guard.sh`) are **aaPanel-specific** — they detect aaPanel's SQLite site database on startup and refuse to run on any other panel (CyberPanel, Plesk, plain LEMP, etc.) so they can't misfire on a host with a different layout.

## Check

```bash
wp cron event list --allow-root | grep pfhd_upload_guard
stat -c '%a %n' wp-admin wp-includes
```
