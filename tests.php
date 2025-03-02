<?php
/**
 * UpdatePulse Server Testing Utilities
 *
 * This file provides testing and debugging functionality for the UpdatePulse Server plugin.
 * It includes tools for performance measurement, logging, and monitoring resource usage.
 * Only loaded when the UPSERV_ENABLE_TEST constant is defined and true.
 *
 * @package UPServ
 * @since 1.0.0
 */

// Ensure required dependencies are available
require_once ABSPATH . 'wp-admin/includes/file.php';

// Global variables for testing configuration
global $upserv_functions_to_test_params, $upserv_functions_to_test, $upserv_actions_to_test, $upserv_filters_to_test, $upserv_output_log, $upserv_tests_show_queries_details, $upserv_tests_show_scripts_details;

// Configure logging preferences - last value overrides previous
$upserv_output_log = 'filelog';
$upserv_output_log = 'serverlog';

// Configure detail level for test output
$upserv_tests_show_queries_details = true;
$upserv_tests_show_scripts_details = true;

/**
 * Log messages or data to a file or server log
 *
 * Writes log entries with timestamps to either the server error log
 * or a dedicated test log file in the plugin's logs directory.
 *
 * @since 1.0.0
 * @param string $message The message to log
 * @param mixed $array_or_object Optional data structure to log
 * @return void
 */
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

/**
 * Format memory usage for human readability
 *
 * Converts raw byte values to a human-readable format with appropriate units (B, K, M, G, T).
 *
 * @since 1.0.0
 * @param int $bytes The number of bytes to format
 * @param int $precision The number of decimal places to display
 * @return string Formatted memory usage with units
 */
function upserv_get_formatted_memory( $bytes, $precision = 2 ) {
	$units  = array( 'B', 'K', 'M', 'G', 'T' );
	$bytes  = max( $bytes, 0 );
	$pow    = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
	$pow    = min( $pow, count( $units ) - 1 );
	$bytes /= ( 1 << ( 10 * $pow ) );

	return round( $bytes, $precision ) . ' ' . $units[ $pow ];
}

/**
 * Log performance statistics for the current request
 *
 * Collects and logs detailed performance metrics including:
 * - Memory usage (total and plugin-specific)
 * - Execution time
 * - Database query counts and details
 * - Included script files
 *
 * This function runs at the end of the request during the shutdown action.
 *
 * @since 1.0.0
 * @return void
 */
function upserv_performance_stats_log() {
	global $wpdb, $upserv_mem_before, $upserv_scripts_before, $upserv_queries_before, $upserv_tests_show_queries_details, $upserv_tests_show_scripts_details;

	// Collect performance metrics
	$mem_after     = memory_get_peak_usage();
	$scripts_after = get_included_files();
	$scripts       = array_diff( $scripts_after, $upserv_scripts_before );
	$query_list    = array();

	// Calculate diff stats for plugin-specific usage
	$plugin_queries = count( $wpdb->queries ) - count( $upserv_queries_before );

	// Format stats for logging
	$query_stats   = 'Number of queries executed by the plugin: ' . $plugin_queries;
	$scripts_stats = 'Number of included/required scripts by the plugin: ' . count( $scripts );
	$mem_stats     = 'Server memory used to run the plugin: ' .
					upserv_get_formatted_memory( $mem_after - $upserv_mem_before ) .
					' / ' . ini_get( 'memory_limit' );

	// Format query data
	foreach ( $wpdb->queries as $query ) {
		$query_list[] = reset( $query );
	}

	// Calculate execution time if available
	$elapsed_time = 'N/A';

	if ( ! empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
		$req_time_float = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_TIME_FLOAT'] ) );

		if ( is_numeric( $req_time_float ) ) {
			$elapsed_time = sprintf( '%.3f', microtime( true ) - $req_time_float );
		}
	}

	// Log all collected metrics
	upserv_tests_log( '========================================================' );
	upserv_tests_log( '--- Start load tests ---' );
	upserv_tests_log( 'Time elapsed: ' . $elapsed_time . 'sec' );
	upserv_tests_log(
		'Total server memory used: ' . upserv_get_formatted_memory( $mem_after ) .
			' / ' . ini_get( 'memory_limit' )
	);
	upserv_tests_log( 'Total number of queries: ' . count( $wpdb->queries ) );
	upserv_tests_log( 'Total number of scripts: ' . count( $scripts_after ) );
	upserv_tests_log( $mem_stats );
	upserv_tests_log( $query_stats );
	upserv_tests_log( $scripts_stats );

	// Log detailed information based on configuration
	if ( $upserv_tests_show_queries_details ) {
		upserv_tests_log( 'Queries: ', $query_list );
	}

	if ( $upserv_tests_show_scripts_details ) {
		upserv_tests_log( 'Scripts: ', $scripts );
	}

	upserv_tests_log( '--- End load tests ---' );
}

// Register the performance logging function to run at the end of request processing
add_action( 'shutdown', 'upserv_performance_stats_log' );
