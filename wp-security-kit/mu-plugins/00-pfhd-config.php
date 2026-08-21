<?php
/** Plugin Name: WP Security Kit Config */
if (!defined('ABSPATH')) exit;

$cfg = '/etc/wp-security-kit/config.conf';
$values = array();
if (@is_readable($cfg)) {
    $parsed = @parse_ini_file($cfg, false, INI_SCANNER_RAW);
    if (is_array($parsed)) $values = $parsed;
}
if (!defined('PFHD_TG_BOT_TOKEN')) define('PFHD_TG_BOT_TOKEN', (string)($values['PFHD_TG_BOT_TOKEN'] ?? ''));
if (!defined('PFHD_TG_CHAT_ID')) define('PFHD_TG_CHAT_ID', (string)($values['PFHD_TG_CHAT_ID'] ?? ''));
if (!defined('WPSK_APPROVAL_SECRET')) define('WPSK_APPROVAL_SECRET', (string)($values['WPSK_APPROVAL_SECRET'] ?? ''));

if (!function_exists('pfhd_tg_alert')) {
    function pfhd_tg_alert($message, $options = array()) {
        if (!PFHD_TG_BOT_TOKEN || !PFHD_TG_CHAT_ID) {
            error_log('[WP Security Kit] Telegram config missing');
            return false;
        }
        $body = array(
            'chat_id' => PFHD_TG_CHAT_ID,
            'text' => "[WP SECURITY] " . wp_strip_all_tags((string)$message),
            'disable_web_page_preview' => 'true',
        );
        if (!empty($options['reply_markup'])) {
            $body['reply_markup'] = wp_json_encode($options['reply_markup']);
        }
        $response = wp_remote_post('https://api.telegram.org/bot' . rawurlencode(PFHD_TG_BOT_TOKEN) . '/sendMessage', array(
            'timeout' => 5,
            'body' => $body,
        ));
        return !is_wp_error($response) && 200 === (int)wp_remote_retrieve_response_code($response);
    }
}
