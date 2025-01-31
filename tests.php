<?php
require_once ABSPATH . 'wp-admin/includes/file.php';
global $upserv_functions_to_test_params, $upserv_functions_to_test, $upserv_actions_to_test, $upserv_filters_to_test, $upserv_output_log, $upserv_tests_show_queries_details, $upserv_tests_show_scripts_details;

$upserv_output_log = 'filelog';
$upserv_output_log = 'serverlog';

$upserv_tests_show_queries_details = true;
$upserv_tests_show_scripts_details = true;

function upserv_tests_log( $message, $array_or_object = null ) {
	global $upserv_output_log;

	date_default_timezone_set( @date_default_timezone_get() ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set, WordPress.PHP.NoSilencedErrors.Discouraged

	$line = date( '[Y-m-d H:i:s O]' ) . ' ' . $message; // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date

	if ( 'serverlog' === $upserv_output_log ) {
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		if ( null !== $array_or_object ) {
			error_log( print_r( $array_or_object, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	if ( 'filelog' === $upserv_output_log ) {
		$log_file = upserv_get_logs_data_dir() . 'tests.log';
		$handle   = fopen( $log_file, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( $handle && flock( $handle, LOCK_EX ) ) {
			$line .= "\n";

			fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

			if ( null !== $array_or_object ) {
				fwrite( $handle, print_r( $array_or_object, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			}

			flock( $handle, LOCK_UN );
		}

		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}
}

function upserv_get_formatted_memory( $bytes, $precision = 2 ) {
	$units  = array( 'B', 'K', 'M', 'G', 'T' );
	$bytes  = max( $bytes, 0 );
	$pow    = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
	$pow    = min( $pow, count( $units ) - 1 );
	$bytes /= ( 1 << ( 10 * $pow ) );

	return round( $bytes, $precision ) . ' ' . $units[ $pow ];
}

function upserv_performance_stats_log() {
	global $wpdb, $upserv_mem_before, $upserv_scripts_before, $upserv_queries_before, $upserv_tests_show_queries_details, $upserv_tests_show_scripts_details;

	$mem_after     = memory_get_peak_usage();
	$query_list    = array();
	$scripts_after = get_included_files();
	$scripts       = array_diff( $scripts_after, $upserv_scripts_before );
	$query_stats   = 'Number of queries executed by the plugin: ' . ( count( $wpdb->queries ) - count( $upserv_queries_before ) );
	$scripts_stats = 'Number of included/required scripts by the plugin: ' . count( $scripts );
	$mem_stats     = 'Server memory used to run the plugin: ' . upserv_get_formatted_memory( $mem_after - $upserv_mem_before ) . ' / ' . ini_get( 'memory_limit' );

	foreach ( $wpdb->queries as $query ) {
		$query_list[] = reset( $query );
	}

	upserv_tests_log( '========================================================' );
	upserv_tests_log( '--- Start load tests ---' );
	upserv_tests_log( 'Time elapsed: ' . sprintf( '%.3f', microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] ) );
	upserv_tests_log( 'Total server memory used: ' . upserv_get_formatted_memory( $mem_after ) . ' / ' . ini_get( 'memory_limit' ) );
	upserv_tests_log( 'Total number of queries: ' . count( $wpdb->queries ) );
	upserv_tests_log( 'Total number of scripts: ' . count( $scripts_after ) );
	upserv_tests_log( $mem_stats );
	upserv_tests_log( $query_stats );
	upserv_tests_log( $scripts_stats );

	if ( $upserv_tests_show_queries_details ) {
		upserv_tests_log( 'Queries: ', $query_list );
	}

	if ( $upserv_tests_show_scripts_details ) {
		upserv_tests_log( 'Scripts: ', $scripts );
	}

	upserv_tests_log( '--- End load tests ---' );
}
add_action( 'shutdown', 'upserv_performance_stats_log' );
