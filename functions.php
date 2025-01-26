<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Anyape\UpdatePulse\Server\Nonce\Nonce;
use Anyape\UpdatePulse\Server\API\License_API;
use Anyape\UpdatePulse\Server\API\Webhook_API;
use Anyape\UpdatePulse\Server\API\Update_API;
use Anyape\UpdatePulse\Server\API\Package_API;
use Anyape\UpdatePulse\Server\Manager\Data_Manager;
use Anyape\UpdatePulse\Server\Manager\Package_Manager;
use Anyape\UpdatePulse\Server\UPServ;

/*******************************************************************
 * Utility functions
 *******************************************************************/

if ( ! function_exists( 'php_log' ) ) {
	function php_log( $message = '', $prefix = '' ) {
		$prefix   = $prefix ? ' ' . $prefix . ' => ' : ' => ';
		$trace    = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$caller   = end( $trace );
		$class    = isset( $caller['class'] ) ? $caller['class'] : '';
		$type     = isset( $caller['type'] ) ? $caller['type'] : '';
		$function = isset( $caller['function'] ) ? $caller['function'] : '';
		$context  = $class . $type . $function . $prefix;

		error_log( $context . print_r( $message, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

if ( ! function_exists( 'cidr_match' ) ) {
	function cidr_match( $ip, $range ) {
		list ( $subnet, $bits ) = explode( '/', $range );
		$ip                     = ip2long( $ip );
		$subnet                 = ip2long( $subnet );

		if ( ! $ip || ! $subnet || ! $bits ) {
			return false;
		}

		$mask    = -1 << ( 32 - $bits );
		$subnet &= $mask; // in case the supplied subnet was not correctly aligned

		return ( $ip & $mask ) === $subnet;
	}
}

if ( ! function_exists( 'access_nested_array' ) ) {
	function access_nested_array( &$_array, $path, $value = null, $update = false ) {
		$keys    = explode( '/', $path );
		$current = &$_array;

		foreach ( $keys as $key ) {

			if ( ! isset( $current[ $key ] ) ) {

				if ( $update ) {
					$current[ $key ] = array();
				} else {
					return null;
				}
			}

			$current = &$current[ $key ];
		}

		if ( $update ) {
			$current = $value;
		}

		return $current;
	}
}

if ( ! function_exists( 'get_vcs_name' ) ) {
	function upserv_get_vcs_name( $type, $context = 'view' ) {

		switch ( $type ) {
			case 'github':
				return 'view' === $context ? __( 'GitHub', 'updatepulse-server' ) : 'GitHub';
			case 'gitlab':
				return 'view' === $context ? __( 'GitLab', 'updatepulse-server' ) : 'GitLab';
			case 'bitbucket':
				return 'view' === $context ? __( 'Bitbucket', 'updatepulse-server' ) : 'Bitbucket';
			default:
				return 'view' === $context ? __( 'Undefined', 'updatepulse-server' ) : null;
		}
	}
}

/*******************************************************************
 * Options functions
 *******************************************************************/

if ( ! function_exists( 'upserv_get_options' ) ) {
	function upserv_get_options() {
		return UPServ::get_instance()->get_options();
	}
}

if ( ! function_exists( 'upserv_update_options' ) ) {
	function upserv_update_options( $options ) {
		return UPServ::get_instance()->update_options( $options );
	}
}

if ( ! function_exists( 'upserv_get_option' ) ) {
	function upserv_get_option( $path ) {
		return UPServ::get_instance()->get_option( $path );
	}
}

if ( ! function_exists( 'upserv_set_option' ) ) {
	function upserv_set_option( $path, $value ) {
		return UPServ::get_instance()->set_option( $path, $value );
	}
}

if ( ! function_exists( 'upserv_update_option' ) ) {
	function upserv_update_option( $path, $value ) {
		return UPServ::get_instance()->update_option( $path, $value );
	}
}

if ( ! function_exists( 'upserv_assets_suffix' ) ) {
	function upserv_assets_suffix() {
		return (bool) ( constant( 'WP_DEBUG' ) ) ? '' : '.min';
	}
}

/*******************************************************************
 * Doing API functions
 *******************************************************************/

if ( ! function_exists( 'upserv_is_doing_license_api_request' ) ) {
	function upserv_is_doing_license_api_request() {
		return License_API::is_doing_api_request();
	}
}

if ( ! function_exists( 'upserv_is_doing_update_api_request' ) ) {
	function upserv_is_doing_update_api_request() {
		return Update_API::is_doing_api_request();
	}
}

if ( ! function_exists( 'upserv_is_doing_webhook_api_request' ) ) {
	function upserv_is_doing_webhook_api_request() {
		return Webhook_API::is_doing_api_request();
	}
}

if ( ! function_exists( 'upserv_is_doing_package_api_request' ) ) {
	function upserv_is_doing_package_api_request() {
		return Package_API::is_doing_api_request();
	}
}

if ( ! function_exists( 'upserv_is_doing_api_request' ) ) {
	function upserv_is_doing_api_request() {
		$mu_doing_api   = wp_cache_get( 'upserv_mu_doing_api', 'updatepulse-server' );
		$is_api_request = $mu_doing_api ?
			$mu_doing_api :
			(
				upserv_is_doing_license_api_request() ||
				upserv_is_doing_update_api_request() ||
				upserv_is_doing_webhook_api_request() ||
				upserv_is_doing_package_api_request()
			);

		return apply_filters( 'upserv_is_api_request', $is_api_request );
	}
}

/*******************************************************************
 * Data ditectories functions
 *******************************************************************/

if ( ! function_exists( 'upserv_get_data_dir' ) ) {
	function upserv_get_data_dir( $dir ) {
		return Data_Manager::get_data_dir( $dir );
	}
}

if ( ! function_exists( 'upserv_get_root_data_dir' ) ) {
	function upserv_get_root_data_dir() {
		return Data_Manager::get_data_dir();
	}
}

if ( ! function_exists( 'upserv_get_packages_data_dir' ) ) {
	function upserv_get_packages_data_dir() {
		return Data_Manager::get_data_dir( 'packages' );
	}
}

if ( ! function_exists( 'upserv_get_logs_data_dir' ) ) {
	function upserv_get_logs_data_dir() {
		return Data_Manager::get_data_dir( 'logs' );
	}
}

if ( ! function_exists( 'upserv_get_cache_data_dir' ) ) {
	function upserv_get_cache_data_dir() {
		return Data_Manager::get_data_dir( 'cache' );
	}
}

if ( ! function_exists( 'upserv_get_package_metadata_data_dir' ) ) {
	function upserv_get_package_metadata_data_dir() {
		return Data_Manager::get_data_dir( 'metadata' );
	}
}

/*******************************************************************
 * Whitelisting functions
 *******************************************************************/

if ( ! function_exists( 'upserv_is_package_whitelisted' ) ) {
	function upserv_is_package_whitelisted( $package_slug ) {
		return Package_Manager::get_instance()->is_package_whitelisted( $package_slug );
	}
}

if ( ! function_exists( 'upserv_whitelist_package' ) ) {
	function upserv_whitelist_package( $package_slug ) {
		return Package_Manager::get_instance()->whitelist_package( $package_slug );
	}
}

if ( ! function_exists( 'upserv_unwhitelist_package' ) ) {
	function upserv_unwhitelist_package( $package_slug ) {
		return Package_Manager::get_instance()->unwhitelist_package( $package_slug );
	}
}

/*******************************************************************
 * Package Metadata functions
 *******************************************************************/

if ( ! function_exists( 'upserv_get_package_metadata' ) ) {
	function upserv_get_package_metadata( $package_slug, $json_encode = false ) {
		return Package_Manager::get_instance()->get_package_metadata(
			$package_slug,
			$json_encode
		);
	}
}

if ( ! function_exists( 'upserv_set_package_metadata' ) ) {
	function upserv_set_package_metadata( $package_slug, $metadata ) {
		return Package_Manager::get_instance()->set_package_metadata(
			$package_slug,
			$metadata
		);
	}
}

/*******************************************************************
 * Cleanup functions
 *******************************************************************/

if ( ! function_exists( 'upserv_force_cleanup_cache' ) ) {
	function upserv_force_cleanup_cache() {
		return Data_Manager::maybe_cleanup( 'cache', true );
	}
}

if ( ! function_exists( 'upserv_force_cleanup_logs' ) ) {
	function upserv_force_cleanup_logs() {
		return Data_Manager::maybe_cleanup( 'logs', true );
	}
}

if ( ! function_exists( 'upserv_force_cleanup_tmp' ) ) {
	function upserv_force_cleanup_tmp() {
		return Data_Manager::maybe_cleanup( 'tmp', true );
	}
}

/*******************************************************************
 * VCS Package functions
 *******************************************************************/

if ( ! function_exists( 'upserv_check_remote_plugin_update' ) ) {
	function upserv_check_remote_plugin_update( $slug ) {
		return upserv_check_remote_package_update( $slug, 'plugin' );
	}
}

if ( ! function_exists( 'upserv_check_remote_theme_update' ) ) {
	function upserv_check_remote_theme_update( $slug ) {
		return upserv_check_remote_package_update( $slug, 'theme' );
	}
}

if ( ! function_exists( 'upserv_check_remote_package_update' ) ) {
	function upserv_check_remote_package_update( $slug, $type ) {
		$api = Update_API::get_instance();

		return $api->check_remote_update( $slug, $type );
	}
}

if ( ! function_exists( 'upserv_download_remote_plugin' ) ) {
	function upserv_download_remote_plugin( $slug, $vcs_url = false, $branch = 'main' ) {
		return upserv_download_remote_package( $slug, 'plugin', $vcs_url, $branch );
	}
}

if ( ! function_exists( 'upserv_download_remote_theme' ) ) {
	function upserv_download_remote_theme( $slug, $vcs_url = false, $branch = 'main' ) {
		return upserv_download_remote_package( $slug, 'theme', $vcs_url, $branch );
	}
}

if ( ! function_exists( 'upserv_download_remote_package' ) ) {
	function upserv_download_remote_package( $slug, $type = 'generic', $vcs_url = false, $branch = 'main' ) {

		if ( $vcs_url ) {
			$vcs_configs     = upserv_get_option( 'vcs', array() );
			$meta            = upserv_get_package_metadata( $slug );
			$meta['type']    = $type;
			$meta['vcs_key'] = hash( 'sha256', trailingslashit( $vcs_url ) . '|' . $branch );
			$meta['origin']  = 'vcs';

			if ( isset( $vcs_configs[ $meta['vcs_key'] ] ) ) {
				upserv_set_package_metadata( $slug, $meta );
			} else {
				return new WP_Error(
					'invalid_vcs',
					__( 'The provided VCS information is not valid', 'updatepulse-server' )
				);
			}
		}

		$api = Update_API::get_instance();

		return $api->download_remote_package( $slug, $type, true );
	}
}

if ( ! function_exists( 'upserv_get_package_vcs_config' ) ) {
	function upserv_get_package_vcs_config( $slug ) {
		$meta = upserv_get_package_metadata( $slug );

		return isset( $meta['vcs_key'] ) ? upserv_get_option( 'vcs/' . $meta['vcs_key'], array() ) : array();
	}
}

/*******************************************************************
 * Package functions
 *******************************************************************/

if ( ! function_exists( 'upserv_delete_package' ) ) {
	function upserv_delete_package( $slug ) {
		$package_manager = Package_Manager::get_instance();

		return (bool) $package_manager->delete_packages_bulk( array( $slug ) );
	}
}

if ( ! function_exists( 'upserv_get_package_info' ) ) {
	function upserv_get_package_info( $package_slug, $json_encode = true ) {
		$result          = $json_encode ? '{}' : array();
		$package_manager = Package_Manager::get_instance();
		$package_info    = $package_manager->get_package_info( $package_slug );

		if ( $package_info ) {
			$result = $json_encode ? wp_json_encode( $package_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : $package_info;
		}

		return $result;
	}
}

if ( ! function_exists( 'upserv_is_package_require_license' ) ) {
	function upserv_is_package_require_license( $package_slug ) {
		$api = License_API::get_instance();

		return $api->is_package_require_license( $package_slug );
	}
}

if ( ! function_exists( 'upserv_get_batch_package_info' ) ) {
	function upserv_get_batch_package_info( $search, $json_encode = true ) {
		$result          = $json_encode ? '{}' : array();
		$package_manager = Package_Manager::get_instance();
		$package_info    = $package_manager->get_batch_package_info( $search );

		if ( $package_info ) {
			$result = $json_encode ? wp_json_encode( $package_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : $package_info;
		}

		return $result;
	}
}

if ( ! function_exists( 'upserv_download_local_package' ) ) {
	function upserv_download_local_package( $package_slug, $package_path = null, $exit_or_die = true ) {
		$package_manager = Package_Manager::get_instance();

		if ( null === $package_path ) {
			$package_path = upserv_get_local_package_path( $package_slug );
		}

		$package_manager->trigger_packages_download( $package_slug, $package_path, $exit_or_die );
	}
}

if ( ! function_exists( 'upserv_get_local_package_path' ) ) {
	function upserv_get_local_package_path( $package_slug ) {
		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			wp_die( __FUNCTION__ . ' - WP_Filesystem not available.' );
		}

		$package_path = trailingslashit( Data_Manager::get_data_dir( 'packages' ) ) . $package_slug . '.zip';

		if ( $wp_filesystem->is_file( $package_path ) ) {
			return $package_path;
		}

		return false;
	}
}

/*******************************************************************
 * Licenses functions
 *******************************************************************/

if ( ! function_exists( 'upserv_browse_licenses' ) ) {
	function upserv_browse_licenses( $license_query ) {
		$api = License_API::get_instance();

		return $api->browse( $license_query );
	}
}

if ( ! function_exists( 'upserv_read_license' ) ) {
	function upserv_read_license( $license_data ) {
		$api = License_API::get_instance();

		return $api->read( $license_data );
	}
}

if ( ! function_exists( 'upserv_add_license' ) ) {
	function upserv_add_license( $license_data ) {

		if ( is_array( $license_data ) && ! isset( $license_data['data'] ) ) {
			$license_data['data'] = array();
		}

		$license_data['data']['operation_timestamp'] = time();
		$license_data['data']['operation']           = 'add';
		$license_data['data']['operation_id']        = bin2hex( random_bytes( 16 ) );
		$api = License_API::get_instance();

		return $api->add( $license_data );
	}
}

if ( ! function_exists( 'upserv_edit_license' ) ) {
	function upserv_edit_license( $license_data ) {

		if ( is_array( $license_data ) && ! isset( $license_data['data'] ) ) {
			$license_data['data'] = array();
		}

		$license_data['data']['operation_timestamp'] = time();
		$license_data['data']['operation']           = 'edit';
		$license_data['data']['operation_id']        = bin2hex( random_bytes( 16 ) );
		$api = License_API::get_instance();

		return $api->edit( $license_data );
	}
}

if ( ! function_exists( 'upserv_delete_license' ) ) {
	function upserv_delete_license( $license_data ) {

		if ( is_array( $license_data ) && ! isset( $license_data['data'] ) ) {
			$license_data['data'] = array();
		}

		$license_data['data']['operation_timestamp'] = time();
		$license_data['data']['operation']           = 'delete';
		$license_data['data']['operation_id']        = bin2hex( random_bytes( 16 ) );
		$api = License_API::get_instance();

		return $api->delete( $license_data );
	}
}

if ( ! function_exists( 'upserv_check_license' ) ) {
	function upserv_check_license( $license_data ) {
		$api = License_API::get_instance();

		return $api->check( $license_data );
	}
}

if ( ! function_exists( 'upserv_activate_license' ) ) {
	function upserv_activate_license( $license_data ) {
		$api = License_API::get_instance();

		return $api->activate( $license_data );
	}
}

if ( ! function_exists( 'upserv_deactivate_license' ) ) {
	function upserv_deactivate_license( $license_data ) {
		$api = License_API::get_instance();

		return $api->deactivate( $license_data );
	}
}

/*******************************************************************
 * Template functions
 *******************************************************************/

if ( ! function_exists( 'upserv_get_template' ) ) {
	function upserv_get_template( $template_name, $args = array(), $load = true, $require_file = false ) {
		$template_name = apply_filters( 'upserv_get_template_name', $template_name, $args );
		$template_args = apply_filters( 'upserv_get_template_args', $args, $template_name );

		if ( ! empty( $template_args ) ) {

			foreach ( $template_args as $key => $arg ) {
				$key = is_numeric( $key ) ? 'var_' . $key : $key;

				set_query_var( $key, $arg );
			}
		}

		return UPServ::locate_template( $template_name, $load, $require_file );
	}
}

if ( ! function_exists( 'upserv_get_admin_template' ) ) {
	function upserv_get_admin_template( $template_name, $args = array(), $load = true, $require_file = false ) {
		$template_name = apply_filters( 'upserv_get_admin_template_name', $template_name, $args );
		$template_args = apply_filters( 'upserv_get_admin_template_args', $args, $template_name );

		if ( ! empty( $template_args ) ) {

			foreach ( $template_args as $key => $arg ) {
				$key = is_numeric( $key ) ? 'var_' . $key : $key;

				set_query_var( $key, $arg );
			}
		}

		return UPServ::locate_admin_template( $template_name, $load, $require_file );
	}
}

/*******************************************************************
 * Nonce functions
 *******************************************************************/

if ( ! function_exists( 'upserv_init_nonce_auth' ) ) {
	function upserv_init_nonce_auth( $private_auth_key ) {
		Nonce::init_auth( $private_auth_key );
	}
}

if ( ! function_exists( 'upserv_create_nonce' ) ) {
	function upserv_create_nonce(
		$true_nonce = true,
		$expiry_length = Nonce::DEFAULT_EXPIRY_LENGTH,
		$data = array(),
		$return_type = Nonce::NONCE_ONLY,
		$store = true
	) {
		return Nonce::create_nonce( $true_nonce, $expiry_length, $data, $return_type, $store );
	}
}

if ( ! function_exists( 'upserv_get_nonce_expiry' ) ) {
	function upserv_get_nonce_expiry( $nonce ) {
		return Nonce::get_nonce_expiry( $nonce );
	}
}

if ( ! function_exists( 'upserv_get_nonce_data' ) ) {
	function upserv_get_nonce_data( $nonce ) {
		return Nonce::get_nonce_data( $nonce );
	}
}

if ( ! function_exists( 'upserv_validate_nonce' ) ) {
	function upserv_validate_nonce( $value ) {
		return Nonce::validate_nonce( $value );
	}
}

if ( ! function_exists( 'upserv_delete_nonce' ) ) {
	function upserv_delete_nonce( $value ) {
		return Nonce::delete_nonce( $value );
	}
}

if ( ! function_exists( 'upserv_clear_nonces' ) ) {
	function upserv_clear_nonces() {
		return Nonce::upserv_nonce_cleanup();
	}
}

if ( ! function_exists( 'upserv_build_nonce_api_signature' ) ) {
	function upserv_build_nonce_api_signature( $api_key_id, $api_key, $timestamp, $payload ) {
		unset( $payload['api_signature'] );
		unset( $payload['api_credentials'] );

		( function ( &$arr ) {
			$recur_ksort = function ( &$arr ) use ( &$recur_ksort ) {

				foreach ( $arr as &$value ) {

					if ( is_array( $value ) ) {
						$recur_ksort( $value );
					}
				}

				ksort( $arr );
			};

			$recur_ksort( $arr );
		} )( $payload );

		$str         = base64_encode( $api_key_id . json_encode( $payload, JSON_NUMERIC_CHECK ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$credentials = $timestamp . '|' . $api_key_id;
		$time_key    = hash_hmac( 'sha256', $timestamp, $api_key, true );
		$signature   = hash_hmac( 'sha256', $str, $time_key );

		return array(
			'credentials' => $credentials,
			'signature'   => $signature,
		);
	}
}

/*******************************************************************
 * Webhook functions
 *******************************************************************/

if ( ! function_exists( 'upserv_schedule_webhook' ) ) {
	function upserv_schedule_webhook( $payload, $event_type, $instant = false ) {

		if ( isset( $payload['event'], $payload['content'] ) ) {
			$api = Webhook_API::get_instance();

			return $api->schedule_webhook( $payload, $event_type, $instant );
		}

		return new WP_Error(
			__FUNCTION__,
			__( 'The webhook payload must contain an event string and a content.', 'updatepulse-server' )
		);
	}
}

if ( ! function_exists( 'upserv_fire_webhook' ) ) {
	function upserv_fire_webhook( $url, $secret, $body, $action ) {

		if (
			filter_var( $url, FILTER_VALIDATE_URL ) &&
			null !== json_decode( $body )
		) {
			$api = Webhook_API::get_instance();

			return $api->fire_webhook( $url, $secret, $body, $action );
		}

		return new WP_Error(
			__FUNCTION__,
			__( '$url must be a valid url and $body must be a JSON string.', 'updatepulse-server' )
		);
	}
}
