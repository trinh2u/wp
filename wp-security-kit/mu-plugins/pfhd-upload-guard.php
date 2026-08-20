<?php
/**
 * Plugin Name: PFHD Upload Guard
 * Description: Rejects executable uploads and alerts when PHP-like files appear in uploads.
 * Version: 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PFHD_UG_CRON_HOOK = 'pfhd_upload_guard_scan';
const PFHD_UG_OPTION   = 'pfhd_upload_guard_state';

function pfhd_ug_alert( $message ) {
	$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$host = $host ? $host : 'unknown-site';
	$root = defined( 'ABSPATH' ) ? wp_normalize_path( ABSPATH ) : 'unknown-root';
	$message = "Site: {$host}\nWebroot: {$root}\n{$message}";
	if ( function_exists( 'pfhd_tg_alert' ) ) {
		pfhd_tg_alert( $message );
	} else {
		error_log( '[PFHD Upload Guard] ' . $message );
	}
}

function pfhd_ug_dangerous_name( $name ) {
	$name = strtolower( (string) $name );
	$name = preg_replace( '/[^a-z0-9._-]/', '', $name );
	return (bool) preg_match( '/\.(?:php\d*|phtml|pht|phar|cgi|pl|py|jsp|asp|aspx|sh)(?:\.|$)/i', $name );
}

add_filter( 'wp_handle_upload_prefilter', function ( $file ) {
	if ( ! empty( $file['name'] ) && pfhd_ug_dangerous_name( $file['name'] ) ) {
		pfhd_ug_alert( 'Blocked dangerous upload name: ' . sanitize_file_name( $file['name'] ) );
		$file['error'] = 'Tệp này không được phép tải lên.';
	}
	return $file;
} );

function pfhd_ug_scan_uploads() {
	$uploads = wp_upload_dir();
	$root    = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
	if ( ! $root || ! is_dir( $root ) ) {
		return;
	}

	$state = get_option( PFHD_UG_OPTION, array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}
	$seen    = array();
	$found   = 0;
	$changed = array();
	$it    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $it as $info ) {
		if ( ! $info->isFile() ) {
			continue;
		}
		$path = $info->getPathname();
		$rel  = ltrim( str_replace( $root, '', $path ), DIRECTORY_SEPARATOR );
		if ( ! preg_match( '/\.(?:php\d*|phtml|pht|phar|cgi|pl|py|jsp|asp|aspx|sh)$/i', $path ) ) {
			continue;
		}
		// WordPress occasionally places a harmless index.php in upload subdirectories.
		if ( strtolower( basename( $path ) ) === 'index.php' ) {
			continue;
		}
		$found++;
		$stat = array( 'size' => (int) $info->getSize(), 'mtime' => (int) $info->getMTime() );
		$seen[ $rel ] = $stat;
		if ( ! isset( $state[ $rel ] ) || $state[ $rel ] !== $stat ) {
			$changed[] = $rel . ' (' . $stat['size'] . ' bytes)';
		}
	}

	update_option( PFHD_UG_OPTION, $seen, false );
	if ( $changed ) {
		$shown = array_slice( $changed, 0, 20 );
		$more  = count( $changed ) - count( $shown );
		$body  = "Executable-like files detected in uploads:\n- " . implode( "\n- ", $shown );
		if ( $more > 0 ) {
			$body .= "\n... plus {$more} more file(s).";
		}
		$body .= "\nAction: web-server rules should prevent execution; investigate and quarantine manually.";
		pfhd_ug_alert( $body );
	}
	if ( $found > 0 ) {
		error_log( '[PFHD Upload Guard] suspicious files in uploads: ' . $found );
	}
}

add_action( PFHD_UG_CRON_HOOK, 'pfhd_ug_scan_uploads' );

// MU-plugins do not run activation hooks; ensure the schedule exists on boot.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( PFHD_UG_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'hourly', PFHD_UG_CRON_HOOK );
	}
}, 20 );

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( PFHD_UG_CRON_HOOK ) ) {
		wp_schedule_event( time() + 60, 'hourly', PFHD_UG_CRON_HOOK );
	}
	pfhd_ug_scan_uploads();
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( PFHD_UG_CRON_HOOK );
} );
