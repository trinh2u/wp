<?php
/**
 * Plugin Name: WP Security Kit Monitor
 * Description: Monitors privileged users, Application Passwords, homepage content, REST publishing, sensitive settings, and PHP code integrity.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const WPSK_MONITOR_HOOK     = 'wpsk_security_monitor_scan';
const WPSK_MONITOR_BASELINE = 'wpsk_security_monitor_baseline';
const WPSK_MONITOR_PENDING  = 'wpsk_security_monitor_pending';
const WPSK_MONITOR_HISTORY  = 'wpsk_security_monitor_history';
const WPSK_MONITOR_NOTICES  = 'wpsk_security_monitor_notices';
const WPSK_MONITOR_VERSION  = 1;

function wpsk_monitor_alert( $code, $message, $severity = 'warning', $context = array() ) {
	$record = array(
		'time'     => time(),
		'code'     => sanitize_key( $code ),
		'severity' => sanitize_key( $severity ),
		'message'  => sanitize_text_field( $message ),
		'context'  => is_array( $context ) ? $context : array(),
	);
	$history   = get_option( WPSK_MONITOR_HISTORY, array() );
	$history   = is_array( $history ) ? $history : array();
	$history[] = $record;
	update_option( WPSK_MONITOR_HISTORY, array_slice( $history, -100 ), false );

	$fingerprint = hash( 'sha256', wp_json_encode( array( $code, $message, $context ) ) );
	$notices     = get_option( WPSK_MONITOR_NOTICES, array() );
	$notices     = is_array( $notices ) ? $notices : array();
	$last_sent   = isset( $notices[ $fingerprint ] ) ? (int) $notices[ $fingerprint ] : 0;
	if ( time() - $last_sent >= HOUR_IN_SECONDS ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$text = sprintf( "[%s] %s\nSite: %s\nEvent: %s", strtoupper( $severity ), $message, $host, $code );
		if ( function_exists( 'pfhd_tg_alert' ) ) pfhd_tg_alert( $text );
		else error_log( '[WP Security Kit] ' . $text );
		$notices[ $fingerprint ] = time();
		if ( count( $notices ) > 200 ) $notices = array_slice( $notices, -200, null, true );
		update_option( WPSK_MONITOR_NOTICES, $notices, false );
	}
}
function wpsk_monitor_hash( $value ) {
	return hash( 'sha256', serialize( $value ) );
}

function wpsk_monitor_admins() {
	$items = array();
	foreach ( get_users( array( 'role' => 'administrator' ) ) as $user ) {
		$items[ (string) $user->ID ] = array(
			'login'      => (string) $user->user_login,
			'email'      => (string) $user->user_email,
			'registered' => (string) $user->user_registered,
			'pass_hash'  => hash( 'sha256', (string) $user->user_pass ),
		);
	}
	ksort( $items );
	return $items;
}

function wpsk_monitor_application_passwords() {
	$items = array();
	if ( ! class_exists( 'WP_Application_Passwords' ) ) return $items;
	foreach ( array_keys( wpsk_monitor_admins() ) as $user_id ) {
		foreach ( WP_Application_Passwords::get_user_application_passwords( (int) $user_id ) as $app ) {
			$key = $user_id . ':' . (string) ( $app['uuid'] ?? '' );
			$items[ $key ] = array(
				'user_id'   => (int) $user_id,
				'uuid'      => (string) ( $app['uuid'] ?? '' ),
				'name'      => (string) ( $app['name'] ?? '' ),
				'created'   => (int) ( $app['created'] ?? 0 ),
				'last_used' => isset( $app['last_used'] ) ? (int) $app['last_used'] : null,
				'last_ip'   => isset( $app['last_ip'] ) ? (string) $app['last_ip'] : null,
			);
		}
	}
	ksort( $items );
	return $items;
}

function wpsk_monitor_homepage() {
	$page_id = (int) get_option( 'page_on_front', 0 );
	$data = array(
		'show_on_front' => (string) get_option( 'show_on_front', 'posts' ),
		'page_on_front' => $page_id,
		'page_for_posts' => (int) get_option( 'page_for_posts', 0 ),
	);
	if ( $page_id ) {
		$page = get_post( $page_id );
		$data['page'] = $page ? array(
			'id'           => (int) $page->ID,
			'title'        => (string) $page->post_title,
			'status'       => (string) $page->post_status,
			'content_hash' => hash( 'sha256', (string) $page->post_content ),
		) : null;
	}
	return $data;
}

function wpsk_monitor_cron_shape() {
	$shape = array();
	$cron  = _get_cron_array();
	foreach ( is_array( $cron ) ? $cron : array() as $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			foreach ( $events as $event ) {
				$shape[] = array( $hook, (string) ( $event['schedule'] ?? '' ), wpsk_monitor_hash( $event['args'] ?? array() ) );
			}
		}
	}
	sort( $shape );
	return $shape;
}

function wpsk_monitor_php_code() {
	$roots = array( WP_PLUGIN_DIR, get_theme_root(), WPMU_PLUGIN_DIR );
	$files = array();
	foreach ( array_unique( $roots ) as $root ) {
		if ( ! is_dir( $root ) ) continue;
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $info ) {
			if ( ! $info->isFile() || ! preg_match( '/\.php$/i', $info->getFilename() ) ) continue;
			$path = $info->getPathname();
			$files[ str_replace( ABSPATH, '', $path ) ] = array( (int) $info->getSize(), hash_file( 'sha256', $path ) );
		}
	}
	ksort( $files );
	return array( 'count' => count( $files ), 'hash' => wpsk_monitor_hash( $files ) );
}

function wpsk_monitor_snapshot( $include_code = true ) {
	$sensitive = array();
	foreach ( array( 'siteurl', 'home', 'users_can_register', 'default_role', 'active_plugins', 'stylesheet', 'template', 'admin_email' ) as $name ) {
		$sensitive[ $name ] = wpsk_monitor_hash( get_option( $name, null ) );
	}
	$snapshot = array(
		'version'               => WPSK_MONITOR_VERSION,
		'captured'              => time(),
		'homepage'              => wpsk_monitor_homepage(),
		'admins'                => wpsk_monitor_admins(),
		'application_passwords' => wpsk_monitor_application_passwords(),
		'sensitive_options'     => $sensitive,
		'cron_shape'            => wpsk_monitor_cron_shape(),
	);
	if ( $include_code ) $snapshot['php_code'] = wpsk_monitor_php_code();
	return $snapshot;
}

function wpsk_monitor_compare_map( $old, $new, $added_code, $removed_code, $changed_code, $label ) {
	foreach ( array_diff_key( $new, $old ) as $key => $item ) wpsk_monitor_alert( $added_code, "$label added: $key", 'critical', $item );
	foreach ( array_diff_key( $old, $new ) as $key => $item ) wpsk_monitor_alert( $removed_code, "$label removed: $key", 'warning', $item );
	foreach ( array_intersect_key( $new, $old ) as $key => $item ) {
		if ( $item !== $old[ $key ] ) wpsk_monitor_alert( $changed_code, "$label changed: $key", 'critical', $item );
	}
}

function wpsk_monitor_scan() {
	$baseline = get_option( WPSK_MONITOR_BASELINE, array() );
	$current  = wpsk_monitor_snapshot( true );
	if ( ! is_array( $baseline ) || empty( $baseline['version'] ) ) {
		update_option( WPSK_MONITOR_BASELINE, $current, false );
		return;
	}
	$changed = array();
	if ( ( $baseline['homepage'] ?? null ) !== $current['homepage'] ) {
		$changed[] = 'homepage';
		wpsk_monitor_alert( 'homepage_changed', 'Homepage selection, title, status, or content changed', 'critical' );
	}
	wpsk_monitor_compare_map( $baseline['admins'] ?? array(), $current['admins'], 'admin_added', 'admin_removed', 'admin_changed', 'Administrator' );
	wpsk_monitor_compare_map( $baseline['application_passwords'] ?? array(), $current['application_passwords'], 'application_password_added', 'application_password_removed', 'application_password_used_or_changed', 'Application Password' );
	if ( ( $baseline['admins'] ?? array() ) !== $current['admins'] ) $changed[] = 'admins';
	if ( ( $baseline['application_passwords'] ?? array() ) !== $current['application_passwords'] ) $changed[] = 'application_passwords';
	foreach ( array( 'sensitive_options', 'cron_shape', 'php_code' ) as $section ) {
		if ( ( $baseline[ $section ] ?? null ) !== ( $current[ $section ] ?? null ) ) {
			$changed[] = $section;
			$severity = 'php_code' === $section ? 'critical' : 'warning';
			wpsk_monitor_alert( $section . '_changed', str_replace( '_', ' ', ucfirst( $section ) ) . ' changed', $severity );
		}
	}
	if ( $changed ) update_option( WPSK_MONITOR_PENDING, array( 'time' => time(), 'sections' => array_values( array_unique( $changed ) ), 'snapshot' => $current ), false );

	global $wpdb;
	$recent = $wpdb->get_results( "SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_date_gmt >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) AND post_type='post' AND post_status IN ('publish','future','draft') ORDER BY ID DESC LIMIT 100", ARRAY_A );
	if ( count( $recent ) >= 5 ) wpsk_monitor_alert( 'post_burst', count( $recent ) . ' posts created within 10 minutes', 'critical', array( 'latest_id' => (int) $recent[0]['ID'] ) );
	$spam = array_filter( $recent, static function ( $post ) { return preg_match( '/casino|gambling|betting|poker|slots?|payday|viagra/i', $post['post_title'] . ' ' . $post['post_name'] ); } );
	if ( $spam ) wpsk_monitor_alert( 'spam_keywords', count( $spam ) . ' recent posts match common spam keywords', 'critical', array( 'latest_id' => (int) reset( $spam )['ID'] ) );
}

add_filter( 'cron_schedules', function ( $schedules ) {
	$schedules['wpsk_five_minutes'] = array( 'interval' => 300, 'display' => 'Every five minutes' );
	return $schedules;
} );
add_action( WPSK_MONITOR_HOOK, 'wpsk_monitor_scan' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( WPSK_MONITOR_HOOK ) ) wp_schedule_event( time() + 60, 'wpsk_five_minutes', WPSK_MONITOR_HOOK );
}, 20 );

add_action( 'user_register', function ( $user_id ) {
	$user = get_userdata( $user_id );
	if ( $user && in_array( 'administrator', (array) $user->roles, true ) ) wpsk_monitor_alert( 'admin_added', 'Administrator account created: ' . $user->user_login, 'critical', array( 'user_id' => (int) $user_id ) );
} );
add_action( 'set_user_role', function ( $user_id, $role ) {
	if ( 'administrator' === $role ) wpsk_monitor_alert( 'admin_role_granted', 'Administrator role granted', 'critical', array( 'user_id' => (int) $user_id ) );
}, 10, 2 );
add_action( 'wp_create_application_password', function ( $user_id, $item ) {
	wpsk_monitor_alert( 'application_password_added', 'Application Password created: ' . (string) ( $item['name'] ?? '(unnamed)' ), 'critical', array( 'user_id' => (int) $user_id, 'uuid' => (string) ( $item['uuid'] ?? '' ) ) );
}, 10, 2 );
add_action( 'application_password_did_authenticate', function ( $user, $item ) {
	wpsk_monitor_alert( 'application_password_authenticated', 'Application Password authenticated: ' . (string) ( $item['name'] ?? '(unnamed)' ), 'warning', array( 'user_id' => (int) $user->ID, 'uuid' => (string) ( $item['uuid'] ?? '' ) ) );
}, 10, 2 );
add_action( 'rest_after_insert_post', function ( $post, $request, $creating ) {
	if ( $creating ) wpsk_monitor_alert( 'rest_post_created', 'Post created through REST API: ' . $post->post_title, 'warning', array( 'post_id' => (int) $post->ID, 'user_id' => get_current_user_id() ) );
}, 10, 3 );

function wpsk_monitor_approve() {
	$pending = get_option( WPSK_MONITOR_PENDING, array() );
	if ( ! is_array( $pending ) || empty( $pending['snapshot'] ) ) return false;
	update_option( WPSK_MONITOR_BASELINE, $pending['snapshot'], false );
	delete_option( WPSK_MONITOR_PENDING );
	return true;
}

add_action( 'admin_menu', function () {
	add_management_page( 'WP Security Kit', 'WP Security Kit', 'manage_options', 'wp-security-kit', 'wpsk_monitor_admin_page' );
} );
function wpsk_monitor_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	if ( isset( $_POST['wpsk_action'] ) && 'approve' === $_POST['wpsk_action'] ) {
		check_admin_referer( 'wpsk_approve_baseline' );
		wpsk_monitor_approve();
		echo '<div class="notice notice-success"><p>Security baseline approved.</p></div>';
	}
	$pending = get_option( WPSK_MONITOR_PENDING, array() );
	$history = array_reverse( (array) get_option( WPSK_MONITOR_HISTORY, array() ) );
	echo '<div class="wrap"><h1>WP Security Kit</h1>';
	echo $pending ? '<div class="notice notice-warning"><p>Unapproved security changes are pending.</p></div>' : '<p>No pending baseline changes.</p>';
	if ( $pending ) {
		echo '<p>Changed sections: <code>' . esc_html( implode( ', ', (array) $pending['sections'] ) ) . '</code></p><form method="post">';
		wp_nonce_field( 'wpsk_approve_baseline' );
		echo '<input type="hidden" name="wpsk_action" value="approve">';
		submit_button( 'Approve current state as baseline', 'primary', 'submit', false );
		echo '</form>';
	}
	echo '<h2>Recent events</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Severity</th><th>Event</th><th>Message</th></tr></thead><tbody>';
	foreach ( array_slice( $history, 0, 50 ) as $item ) printf( '<tr><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>', esc_html( wp_date( 'Y-m-d H:i:s', (int) $item['time'] ) ), esc_html( $item['severity'] ), esc_html( $item['code'] ), esc_html( $item['message'] ) );
	echo '</tbody></table></div>';
}
