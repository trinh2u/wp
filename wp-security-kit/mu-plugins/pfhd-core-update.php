<?php
/**
 * Plugin Name: PFHD Safe Core Update
 * Description: Temporarily unlocks WordPress core only during Dashboard core updates, then locks it again.
 * Version: 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PFHD_CORE_STATE = 'pfhd_core_update_state';

function pfhd_core_is_update( $hook_extra ) {
	return is_array( $hook_extra )
		&& isset( $hook_extra['action'], $hook_extra['type'] )
		&& 'update' === $hook_extra['action']
		&& 'core' === $hook_extra['type'];
}

function pfhd_core_chmod_tree( $dir, $dir_mode, $file_mode ) {
	if ( ! is_dir( $dir ) ) {
		return false;
	}
	@chmod( $dir, $dir_mode );
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);
	foreach ( $it as $info ) {
		@chmod( $info->getPathname(), $info->isDir() ? $dir_mode : $file_mode );
	}
	return true;
}

function pfhd_core_unlock() {
	$root = rtrim( ABSPATH, '/' );
	pfhd_core_chmod_tree( $root . '/wp-admin', 0755, 0644 );
	pfhd_core_chmod_tree( $root . '/wp-includes', 0755, 0644 );
	// Core update can replace root PHP files, but never make wp-config writable.
	foreach ( glob( $root . '/*.php' ) ?: array() as $file ) {
		if ( 'wp-config.php' !== basename( $file ) ) {
			@chmod( $file, 0644 );
		}
	}
	update_option( PFHD_CORE_STATE, array( 'started' => time(), 'root' => $root ), false );
}

function pfhd_core_lock() {
	$root = rtrim( ABSPATH, '/' );
	pfhd_core_chmod_tree( $root . '/wp-admin', 0555, 0444 );
	pfhd_core_chmod_tree( $root . '/wp-includes', 0555, 0444 );
	foreach ( glob( $root . '/*.php' ) ?: array() as $file ) {
		if ( 'wp-config.php' !== basename( $file ) ) {
			@chmod( $file, 0444 );
		}
	}
	delete_option( PFHD_CORE_STATE );
}

function pfhd_core_alert( $message ) {
	if ( function_exists( 'pfhd_tg_alert' ) ) {
		pfhd_tg_alert( $message );
	} else {
		error_log( '[PFHD Core Update] ' . $message );
	}
}

// Runs immediately before WordPress starts installing the core package.
add_filter( 'upgrader_pre_install', function ( $result, $hook_extra ) {
	if ( pfhd_core_is_update( $hook_extra ) ) {
		pfhd_core_unlock();
		pfhd_core_alert( 'Core update bắt đầu: đã mở quyền ghi tạm thời.' );
	}
	return $result;
}, 1, 2 );

// Runs after a successful or failed upgrader process.
add_action( 'upgrader_process_complete', function ( $upgrader, $hook_extra ) {
	if ( pfhd_core_is_update( $hook_extra ) ) {
		pfhd_core_lock();
		pfhd_core_alert( 'Core update kết thúc: đã khóa lại quyền ghi.' );
	}
}, 9999, 2 );

// Covers package-install failures where process_complete is not reached.
add_filter( 'upgrader_install_package_result', function ( $result, $hook_extra ) {
	if ( pfhd_core_is_update( $hook_extra ) && is_wp_error( $result ) ) {
		pfhd_core_lock();
		pfhd_core_alert( 'Core update lỗi: đã khóa lại quyền ghi. ' . $result->get_error_message() );
	}
	return $result;
}, 9999, 2 );

// Safety net: never leave core writable indefinitely after a killed request.
add_action( 'init', function () {
	$state = get_option( PFHD_CORE_STATE, array() );
	if ( is_array( $state ) && ! empty( $state['started'] ) && ( time() - (int) $state['started'] ) > 900 ) {
		pfhd_core_lock();
		pfhd_core_alert( 'Watchdog: phát hiện core mở quyền quá 15 phút, đã tự khóa lại.' );
	}
}, 99 );

