# WP Security Kit

  MU-plugin and installer for WordPress sites on a private VPS.

  Features:

  - Upload Guard
  - PHP detection in uploads
  - Protected WordPress core updates
  - Telegram alerts
  - `.htaccess` hardening
  - VPS-wide installer

  ## Install

  ```bash
  git clone https://github.com/trinh2u/wp.git
  cd wp/wp-security-kit
  chmod +x install.sh
  sudo ./install.sh --root=/home

  Check:

  sudo ./install.sh --root=/home --dry-run

  Installer will be get Telegram bot token and group/chat ID, save at:

  /etc/wp-security-kit/config.conf

 ## Check

  wp cron event list --allow-root | grep pfhd_upload_guard
  stat -c '%a %n' wp-admin wp-includes

  See [README.md](README.md) for installation instructions.
