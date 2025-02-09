<?php

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit; // Exit if accessed directly}
}

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

$upserv_mu_plugin = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) . 'upserv-default-optimizer.php';
$wp_upload_dir    = wp_upload_dir();
$upserv_dir       = trailingslashit( $wp_upload_dir['basedir'] . '/updatepulse-server' );

$wp_filesystem->delete( $upserv_mu_plugin );
$wp_filesystem->delete( $upserv_mdir, true );

as_unschedule_all_actions( 'upserv_cleanup' );

$sql = "DELETE FROM $wpdb->options WHERE `option_name` LIKE %s";

$wpdb->query( $wpdb->prepare( $sql, '%upserv_%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$sql = "DROP TABLE IF EXISTS {$wpdb->prefix}upserv_licenses;";

$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

$sql = "DROP TABLE IF EXISTS {$wpdb->prefix}upserv_nonce;";

$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
