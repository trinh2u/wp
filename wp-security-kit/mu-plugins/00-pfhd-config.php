<?php
/** Plugin Name: WP Security Kit Config */
if (!defined('ABSPATH')) exit;

$cfg = '/etc/wp-security-kit/config.conf';
$values = is_readable($cfg) ? parse_ini_file($cfg, false, INI_SCANNER_RAW) : array();
if (!defined('PFHD_TG_BOT_TOKEN')) define('PFHD_TG_BOT_TOKEN', (string)($values['PFHD_TG_BOT_TOKEN'] ?? ''));
if (!defined('PFHD_TG_CHAT_ID')) define('PFHD_TG_CHAT_ID', (string)($values['PFHD_TG_CHAT_ID'] ?? ''));

if (!function_exists('pfhd_tg_alert')) {
    function pfhd_tg_alert($message) {
        if (!PFHD_TG_BOT_TOKEN || !PFHD_TG_CHAT_ID) {
            error_log('[WP Security Kit] Telegram config missing');
            return false;
        }
        $body = array(
            'chat_id' => PFHD_TG_CHAT_ID,
            'text' => "[WP SECURITY] " . wp_strip_all_tags((string)$message),
            'disable_web_page_preview' => 'true',
        );
        $response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode(PFHD_TG_BOT_TOKEN) . '/sendMessage', array(
            'timeout' => 5,
            'body' => $body,
        ));
        return !is_wp_error($response) && 200 === (int)wp_remote_retrieve_response_code($response);
    }
}
