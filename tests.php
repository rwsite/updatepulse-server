<?php
require_once ABSPATH . 'wp-admin/includes/file.php';
global $upserv_functions_to_test_params, $upserv_functions_to_test, $upserv_actions_to_test, $upserv_filters_to_test, $upserv_output_log, $upserv_tests_show_queries_details, $upserv_tests_show_scripts_details;

// $upserv_output_log                 = 'serverlog';
// $upserv_output_log                 = 'filelog';
// $upserv_tests_show_queries_details = true;
// $upserv_tests_show_scripts_details = true;

$upserv_functions_to_test_params = array(
	'test_plugin_slug'                => 'dummy-plugin',
	'test_theme_slug'                 => 'dummy-theme',
	'test_package_slug'               => 'dummy-plugin',
	'test_package_path'               => null,
	'test_browse_licenses_payload'    => array(
		'relationship' => 'AND',
		'limit'        => 10,
		'offset'       => 0,
		'order_by'     => 'date_created',
		'criteria'     => array(
			array(
				'field'    => 'email',
				'value'    => 'test@%',
				'operator' => 'LIKE',
			),
			array(
				'field'    => 'license_key',
				'value'    => 'test-license',
				'operator' => '=',
			),
		),
	),
	'test_add_license_payload'        => array(
		'license_key'         => 'test-license',
		'status'              => 'blocked',
		'max_allowed_domains' => '3',
		'allowed_domains'     => array(
			'test.test.com',
			'test2.test.com',
		),
		'owner_name'          => 'Test Owner',
		'email'               => 'test@test.com',
		'company_name'        => 'Test Company',
		'txn_id'              => '1111-test-license',
		'date_created'        => '2018-07-09',
		'date_expiry'         => '2099-07-09',
		'date_renewed'        => '2098-07-09',
		'package_slug'        => 'test-license-create',
		'package_type'        => 'plugin',
	),
	'test_read_license_payload'       => array( 'license_key' => 'test-license' ),
	'test_edit_license_payload'       => array(
		'license_key' => 'test-license',
		'status'      => 'pending',
	),
	'test_check_license_payload'      => array( 'license_key' => 'test-license' ),
	'test_activate_license_payload'   => array(
		'license_key'     => 'test-license',
		'allowed_domains' => 'test3.test.com',
	),
	'test_deactivate_license_payload' => array(
		'license_key'     => 'test-license',
		'allowed_domains' => 'test3.test.com',
	),
	'test_delete_license_payload'     => array( 'license_key' => 'test-license' ),
);
$upserv_functions_to_test        = array(
	// /** Functions **/                       /** Parameters **/
	// /** Plugin Data functions **/
	// 'upserv_get_root_data_dir'               => array(),
	// 'upserv_get_packages_data_dir'           => array(),
	// 'upserv_get_logs_data_dir'               => array(),
	// 'upserv_force_cleanup_cache'             => array(),
	// 'upserv_force_cleanup_logs'              => array(),
	// 'upserv_force_cleanup_tmp'               => array(),
	// /** Update Server functions **/
	// 'upserv_is_doing_update_api_request'     => array(),
	// 'upserv_check_remote_theme_update'       => array( $upserv_functions_to_test_params['test_theme_slug'] ),
	// 'upserv_download_remote_theme_to_local'  => array( $upserv_functions_to_test_params['test_theme_slug'] ),
	// 'upserv_check_remote_plugin_update'      => array( $upserv_functions_to_test_params['test_plugin_slug'] ),
	// 'upserv_download_remote_plugin_to_local' => array( $upserv_functions_to_test_params['test_plugin_slug'] ),
	// 'upserv_get_local_package_path'          => array( $upserv_functions_to_test_params['test_package_slug'] ),
	// 'upserv_download_local_package'          => array( $upserv_functions_to_test_params['test_package_slug'], $upserv_functions_to_test_params['test_package_path'] ),
	// /** Licenses functions **/
	// 'upserv_is_doing_license_api_request'    => array(),
	// 'upserv_add_license'                     => array( $upserv_functions_to_test_params['test_add_license_payload'] ),
	// 'upserv_check_license'                   => array( $upserv_functions_to_test_params['test_check_license_payload'] ),
	// 'upserv_read_license'                    => array( $upserv_functions_to_test_params['test_read_license_payload'] ),
	// 'upserv_edit_license'                    => array( $upserv_functions_to_test_params['test_edit_license_payload'] ),
	// 'upserv_browse_licenses'                 => array( $upserv_functions_to_test_params['test_browse_licenses_payload'] ),
	// 'upserv_activate_license'                => array( $upserv_functions_to_test_params['test_activate_license_payload'] ),
	// 'upserv_deactivate_license'              => array( $upserv_functions_to_test_params['test_deactivate_license_payload'] ),
	// 'upserv_delete_license'                  => array( $upserv_functions_to_test_params['test_delete_license_payload'] ),
);

$upserv_actions_to_test = array(
	// /** Actions **/                                      /** Parameters **/
	// /** Plugin Data actions **/
	// 'upserv_scheduled_cleanup_event'                      => 6, // bool $result, string $type, int $timestamp, string $frequency, string $hook, array $params
	// 'upserv_registered_cleanup_schedule'                  => 2, // string $type, array $params
	// 'upserv_cleared_cleanup_schedule'                     => 2, // string $type, array $params
	// 'upserv_did_cleanup'                                  => 4, // bool $result, string $type, int $size, bool $force
	// /** Update Server actions **/
	// 'upserv_primed_package_from_remote'                   => 2, // bool $result, string $slug
	// 'upserv_scheduled_check_remote_event'                 => 6, // bool $result, string $slug, int $timestamp, string $frequency, string $hook, array $params
	// 'upserv_registered_check_remote_schedule'             => 3, // string $slug, string $scheduled_hook, string $action_hook
	// 'upserv_cleared_check_remote_schedule'                => 3, // string $slug, string $scheduled_hook, array $params
	// 'upserv_downloaded_remote_package'                    => 3, // mixed $package, string $type, string $slug
	// 'upserv_saved_remote_package_to_local'                => 3, // bool $result, string $type, string $slug
	// 'upserv_checked_remote_package_update'                => 3, // bool $has_update, string $type, string $slug
	// 'upserv_did_manual_upload_package'                    => 3, // bool $result, string $type, string $slug
	// 'upserv_before_packages_download'                     => 3, // string $archive_name, string $archive_path, array $package_slugs
	// 'upserv_triggered_package_download'                   => 2, // string $archive_name, string $archive_path
	// 'upserv_before_handle_update_request'                 => 1, // array $request_params
	// 'upserv_deleted_package'                              => 3, // bool $result, string $type, string $slug
	// 'upserv_before_remote_package_zip'                    => 3, // string $package_slug, string $files_path, string $archive_path
	// /** Licenses actions **/
	// 'upserv_added_license_check'                          => 1, // string $package_slug
	// 'upserv_removed_license_check'                        => 1, // string $package_slug
	// 'upserv_registered_license_schedule'                  => 1, // array $scheduled_hook
	// 'upserv_cleared_license_schedule'                     => 1, // array $scheduled_hook
	// 'upserv_scheduled_license_event'                      => 4, // bool $result, int $timestamp, string $frequency, string $hook
	// 'upserv_browse_licenses'                              => 1, // array $payload
	// 'upserv_did_browse_licenses'                          => 1, // stdClass $license
	// 'upserv_did_read_license'                             => 1, // stdClass $license
	// 'upserv_did_edit_license'                             => 1, // stdClass $license
	// 'upserv_did_add_license'                              => 1, // stdClass $license
	// 'upserv_did_delete_license'                           => 1, // stdClass $license
	// 'upserv_did_check_license'                            => 1, // mixed $result
	// 'upserv_did_activate_license'                         => 1, // mixed $result
	// 'upserv_did_deactivate_license'                       => 1, // mixed $result
);

$upserv_filters_to_test = array(
	// /** Filters **/                                     /** Parameters **/
	// /** Plugin Data filters **/
	// 'upserv_submitted_data_config'                       => 1, // array $config
	// 'upserv_schedule_cleanup_frequency'                  => 2, // string $frequency, string $type
	// /** Update Server filters **/
	// 'upserv_update_server'                               => 4, // mixed $update_server, array $config, string $slug, bool $use_license
	// 'upserv_handle_update_request_params'                => 1, // array $params
	// 'upserv_update_checker'                              => 8, // mixed $update_checker, string $slug, string $type, string $package_file_name, string $repository_service_url, string $repository_branch, mixed $repository_credentials, bool $repository_service_self_hosted
	// 'upserv_update_api_config'                           => 1, // array $config
	// 'upserv_submitted_remote_sources_config'             => 1, // array $config
	// 'upserv_check_remote_frequency'                      => 2, // string $frequency, string $slug
	// /** Licenses filters **/
	// 'upserv_license_valid'                               => 2, // bool $isValid, mixed $license, string $license_signature
	// 'upserv_license_server'                              => 1, // mixed $license_server
	// 'upserv_license_api_config'                          => 1, // array $config
	// 'upserv_licensed_package_slugs'                      => 1, // array $package_slugs
	// 'upserv_submitted_licenses_config'                   => 1, // array $config
	// 'upserv_check_license_result'                        => 2, // mixed $result, array $license_data
	// 'upserv_activate_license_result'                     => 3, // mixed $result, array $license_data, mixed $license
	// 'upserv_deactivate_license_result'                   => 3, // mixed $result, array $license_data, mixed $license
	// 'upserv_activate_license_dirty_payload'              => 1, // array $dirty_payload
	// 'upserv_deactivate_license_dirty_payload'            => 1, // array $dirty_payload
	// 'upserv_browse_licenses_payload'                     => 1, // array $payload
	// 'upserv_read_license_payload'                        => 1, // array $payload
	// 'upserv_edit_license_payload'                        => 1, // array $payload
	// 'upserv_add_license_payload'                         => 1, // array $payload
	// 'upserv_delete_license_payload'                      => 1, // array $payload
	// 'upserv_check_license_dirty_payload'                 => 1, // array $payload
	// 'upserv_activate_license_payload'                    => 1, // array $payload
	// 'upserv_deactivate_license_payload'                  => 1, // array $payload
);

if ( ! empty( $upserv_functions_to_test ) && ! has_action( 'plugins_loaded', 'upserv_ready_for_function_tests' ) ) {
	function upserv_ready_for_function_tests() {
		upserv_run_tests( 'functions' );
	}
	add_action( 'plugins_loaded', 'upserv_ready_for_function_tests', 6 );
}

if ( ! empty( $upserv_actions_to_test ) && ! has_action( 'init', 'upserv_ready_for_action_tests' ) ) {
	function upserv_ready_for_action_tests() {
		upserv_run_tests( 'actions' );
	}
	add_action( 'init', 'upserv_ready_for_action_tests', 10 );
}

if ( ! empty( $upserv_filters_to_test ) && ! has_filter( 'init', 'upserv_ready_for_filter_tests' ) ) {
	function upserv_ready_for_filter_tests() {
		upserv_run_tests( 'filters' );
	}
	add_action( 'init', 'upserv_ready_for_filter_tests', 10 );
}

function upserv_run_tests( $test ) {

	if ( wp_doing_ajax() ) {

		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once UPSERV_PLUGIN_PATH . 'functions.php';
	global $upserv_functions_to_test_params, $upserv_functions_to_test, $upserv_actions_to_test, $upserv_filters_to_test;

	if ( 'functions' === $test ) {
		upserv_functions_test_func( $upserv_functions_to_test, $upserv_functions_to_test_params );
	}

	if ( 'actions' === $test ) {

		if ( ! empty( $upserv_actions_to_test ) ) {

			foreach ( $upserv_actions_to_test as $action => $num_params ) {
				add_action( $action, 'upserv_action_test_hook', 10, $num_params );
			}
		}
	}

	if ( 'filters' === $test ) {

		if ( ! empty( $upserv_filters_to_test ) ) {

			foreach ( $upserv_filters_to_test as $filter => $num_params ) {
				add_action( $filter, 'upserv_filter_test_hook', 10, $num_params );
			}
		}
	}
}

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

function upserv_functions_test_func( $functions, $test_params ) {
	$start_message            = '========================================================';
	$header_delimiter_message = '--------------------------------------------------------';
	$delimiter_message        = '------';

	if ( ! empty( $functions ) ) {
		upserv_tests_log( $start_message );
		upserv_tests_log( 'Start functions test with the following parameters: ', $test_params );
		upserv_tests_log( $header_delimiter_message );

		foreach ( $functions as $function_name => $params ) {
			$message = $function_name . ' called with params: ';

			upserv_tests_log( $message, $params );

			$result = call_user_func_array( $function_name, $params );

			if ( ! is_array( $result ) ) {
				$result = array( $result );
			}

			$message = 'Result: ';

			upserv_tests_log( $message, $result );
			upserv_tests_log( $delimiter_message );
		}

		upserv_tests_log( '--- End functions test ---' );
	}
}

function upserv_action_test_hook() {
	$start_message = '========================================================';
	$message       = current_filter() . ' called with params: ';

	upserv_tests_log( $start_message );
	upserv_tests_log( '--- Start ' . current_filter() . ' action test ---' );
	upserv_tests_log( $message, func_get_args() );
	upserv_tests_log( '--- End ' . current_filter() . ' action test ---' );
}

function upserv_filter_test_hook() {
	$start_message = '========================================================';
	$message       = current_filter() . ' called with params: ';
	$params        = func_get_args();

	upserv_tests_log( $start_message );
	upserv_tests_log( '--- Start ' . current_filter() . ' filter test ---' );
	upserv_tests_log( $message, $params );
	upserv_tests_log( '--- End ' . current_filter() . ' filter test ---' );

	return reset( $params );
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
