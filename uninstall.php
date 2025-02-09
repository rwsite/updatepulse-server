<?php

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Exit if accessed directly}
}

function upserv_uninstall() {
	global $wpdb;

	WP_Filesystem();

	global $wp_filesystem;

	$cron = get_option( 'cron' );

	foreach ( $cron as $job ) {

		if ( is_array( $job ) ) {
			$keys = array_keys( $job );

			foreach ( $keys as $key ) {

				if ( 0 === strpos( $key, 'upserv_' ) ) {
					wp_unschedule_hook( $key );
				}
			}
		}
	}

	$mu_plugin_path = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
	$mu_plugins     = $wp_filesystem->dirlist( $mu_plugin_path );

	foreach ( $mu_plugins as $_mu_plugin ) {

		if ( preg_match( '/^upserv-.*-optimizer\.php$/', $_mu_plugin['name'] ) ) {
			$wp_filesystem->delete( $mu_plugin_path . $_mu_plugin['name'] );
		}
	}

	$wp_upload_dir = wp_upload_dir();
	$upserv_dir    = trailingslashit( $wp_upload_dir['basedir'] . '/updatepulse-server' );

	$wp_filesystem->delete( $upserv_dir, true );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'upserv_cleanup' );
	}

	wp_unschedule_hook( 'upserv_cleanup' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE `option_name` LIKE %s", '%upserv_%' ) );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upserv_licenses;" );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}upserv_nonce;" );
}

upserv_uninstall();
