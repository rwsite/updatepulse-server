<?php

namespace Anyape\UpdatePulse\Server\Nonce;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use DateTime;
use DateTimeZone;
use PasswordHash;
use Anyape\Utils\Utils;
use Anyape\UpdatePulse\Server\Scheduler\Scheduler;

class Nonce {

	const DEFAULT_EXPIRY_LENGTH = MINUTE_IN_SECONDS / 2;
	const NONCE_ONLY            = 1;
	const NONCE_INFO_ARRAY      = 2;

	protected static $true_nonce;
	protected static $expiry_length;
	protected static $doing_api_request = null;
	protected static $private_keys;

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// API action --------------------------------------------------

	// WordPress hooks ---------------------------------------------

	public static function activate() {
		$result = self::maybe_create_or_upgrade_db();

		if ( ! $result ) {
			$error_message = __( 'Failed to create the necessary database table(s).', 'updatepulse-server' );

			die( $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public static function deactivate() {
		Scheduler::get_instance()->unschedule_all_actions( 'upserv_nonce_cleanup' );
	}

	public static function uninstall() {}

	public static function upserv_scheduler_init() {

		if ( Scheduler::get_instance()->has_scheduled_action( 'upserv_nonce_cleanup' ) ) {
			return;
		}

		$d = new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) );

		$d->setTime( 0, 0, 0 );
		Scheduler::get_instance()->schedule_recurring_action(
			$d->getTimestamp() + DAY_IN_SECONDS,
			DAY_IN_SECONDS,
			'upserv_nonce_cleanup'
		);
	}

	public static function add_endpoints() {
		add_rewrite_rule(
			'^updatepulse-server-token/*?$',
			'index.php?$matches[1]&action=token&__upserv_nonce_api=1&',
			'top'
		);
		add_rewrite_rule(
			'^updatepulse-server-nonce/*?$',
			'index.php?$matches[1]&action=nonce&__upserv_nonce_api=1&',
			'top'
		);
	}

	public static function parse_request() {
		global $wp;

		if ( ! isset( $wp->query_vars['__upserv_nonce_api'] ) ) {
			return;
		}

		$code     = 400;
		$response = array(
			'code'    => 'action_not_found',
			'message' => __( 'Malformed request', 'updatepulse-server' ),
		);

		if ( ! self::authorize() ) {
			$code     = 403;
			$response = array(
				'code'    => 'unauthorized',
				'message' => __( 'Unauthorized access.', 'updatepulse-server' ),
			);
		} elseif ( isset( $wp->query_vars['action'] ) ) {
			$method  = $wp->query_vars['action'];
			$payload = $wp->query_vars;

			unset( $payload['action'] );

			$payload = apply_filters( 'upserv_nonce_api_payload', $payload, $method );

			if (
				is_string( $wp->query_vars['action'] ) &&
				method_exists(
					get_class(),
					'generate_' . $wp->query_vars['action'] . '_api_response'
				)
			) {
				$method   = 'generate_' . $wp->query_vars['action'] . '_api_response';
				$response = self::$method( $payload );

				if ( $response ) {
					$code                     = 200;
					$response['time_elapsed'] = sprintf( '%.3f', microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] );
				} else {
					$code     = 500;
					$response = array(
						'code'    => 'internal_error',
						'message' => __( 'Internal Error - nonce insert error', 'updatepulse-server' ),
					);

					Utils::php_log( __METHOD__ . ' wpdb::insert error' );
				}
			}
		}

		$code     = apply_filters( 'upserv_nonce_api_code', $code, $wp->query_vars );
		$response = apply_filters( 'upserv_nonce_api_response', $response, $code, $wp->query_vars );

		wp_send_json( $response, $code );
	}

	public static function query_vars( $query_vars ) {
		$query_vars = array_merge(
			$query_vars,
			array(
				'__upserv_nonce_api',
				'api_signature',
				'api_credentials',
				'action',
				'expiry_length',
				'data',
			)
		);

		return $query_vars;
	}

	// Overrides ---------------------------------------------------

	// Misc. -------------------------------------------------------

	public static function maybe_create_or_upgrade_db() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = '';

		if ( ! empty( $wpdb->charset ) ) {
			$charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
		}

		if ( ! empty( $wpdb->collate ) ) {
			$charset_collate .= " COLLATE {$wpdb->collate}";
		}

		$sql =
			"CREATE TABLE {$wpdb->prefix}upserv_nonce (
				id int(12) NOT NULL auto_increment,
				nonce varchar(255) NOT NULL,
				true_nonce tinyint(2) NOT NULL DEFAULT '1',
				expiry int(12) NOT NULL,
				data longtext NOT NULL,
				PRIMARY KEY (id),
				KEY nonce (nonce)
			) {$charset_collate};";

		dbDelta( $sql );

		$table_name = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}upserv_nonce'" );

		if ( "{$wpdb->prefix}upserv_nonce" !== $table_name ) {
			return false;
		}

		return true;
	}

	public static function register() {

		if ( ! self::is_doing_api_request() ) {
			add_action( 'upserv_scheduler_init', array( get_class(), 'upserv_scheduler_init' ) );
			add_action( 'upserv_nonce_cleanup', array( get_class(), 'upserv_nonce_cleanup' ) );
		}

		add_action( 'init', array( get_class(), 'add_endpoints' ) );
		add_action( 'parse_request', array( get_class(), 'parse_request' ), -99, 0 );

		add_filter( 'query_vars', array( get_class(), 'query_vars' ), -99, 1 );
	}

	public static function init_auth( $private_keys ) {
		self::$private_keys = $private_keys;
	}

	public static function is_doing_api_request() {

		if ( null === self::$doing_api_request ) {
			self::$doing_api_request = Utils::is_url_subpath_match( '/^updatepulse-server-(nonce|token)$/' );
		}

		return self::$doing_api_request;
	}

	public static function create_nonce(
		$true_nonce = true,
		$expiry_length = self::DEFAULT_EXPIRY_LENGTH,
		$data = array(),
		$return_type = self::NONCE_ONLY,
		$store = true
	) {
		$nonce = apply_filters(
			'upserv_created_nonce',
			false,
			$true_nonce,
			$expiry_length,
			$data,
			$return_type
		);

		if ( ! $nonce ) {
			$id    = self::generate_id();
			$nonce = md5( wp_salt( 'nonce' ) . $id . microtime( true ) );
		}

		$data = is_array( $data ) ? filter_var_array( $data, FILTER_SANITIZE_FULL_SPECIAL_CHARS ) : false;

		if ( $data && isset( $data['test'] ) && 1 === intval( $data['test'] ) ) {
			$store = false;
		}

		$permanent = false;

		if ( isset( $data['permanent'] ) ) {
			$data['permanent'] = filter_var( $data['permanent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$permanent         = (bool) $data['permanent'];
		}

		$expiry = $permanent ? 0 : time() + abs( intval( $expiry_length ) );
		$data   = $data ? wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) : '{}';

		if ( $store ) {
			$result = self::store_nonce( $nonce, (bool) $true_nonce, $expiry, $data );
		} else {
			$result = array(
				'nonce'      => $nonce,
				'true_nonce' => (bool) $true_nonce,
				'expiry'     => $expiry,
				'data'       => $data,
			);
		}

		if ( self::NONCE_INFO_ARRAY === $return_type ) {

			if ( is_array( $result ) ) {
				$result['data'] = json_decode( $result['data'], true );
			}

			$return = $result;
		} else {
			$return = ( $result ) ? $result['nonce'] : $result;
		}

		return $return;
	}

	public static function get_nonce_expiry( $nonce ) {
		global $wpdb;

		$row = wp_cache_get( 'nonce_' . $nonce, 'updatepulse-server', false, $found );

		if ( ! $found ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}upserv_nonce WHERE nonce = %s;", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$nonce
				)
			);

			wp_cache_set( 'nonce_' . $nonce, $row, 'updatepulse-server' );
		}

		if ( ! $row ) {
			$nonce_expiry = 0;
		} else {
			$nonce_expiry = $row->expiry;
		}

		return intval( $nonce_expiry );
	}

	public static function get_nonce_data( $nonce ) {
		global $wpdb;

		$row = wp_cache_get( 'nonce_' . $nonce, 'updatepulse-server', false, $found );

		if ( ! $found ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}upserv_nonce WHERE nonce = %s;", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$nonce
				)
			);

			wp_cache_set( 'nonce_' . $nonce, $row, 'updatepulse-server' );
		}

		if ( ! $row ) {
			$data = array();
		} else {
			$data = is_string( $row->data ) ? json_decode( $row->data, true ) : array();
		}

		return $data;
	}

	public static function validate_nonce( $value ) {

		if ( empty( $value ) ) {
			return false;
		}

		$nonce = self::fetch_nonce( $value );
		$valid = ( $nonce === $value );

		return $valid;
	}


	public static function delete_nonce( $value ) {
		global $wpdb;

		$result = $wpdb->delete( "{$wpdb->prefix}upserv_nonce", array( 'nonce' => $value ) );

		wp_cache_delete( 'nonce_' . $value, 'updatepulse-server' );

		return (bool) $result;
	}

	public static function upserv_nonce_cleanup() {

		if ( defined( 'WP_SETUP_CONFIG' ) || defined( 'WP_INSTALLING' ) ) {
			return;
		}

		global $wpdb;

		$sql      = "DELETE FROM {$wpdb->prefix}upserv_nonce
			WHERE expiry < %d
			AND (
				JSON_VALID(`data`) = 1
				AND (
					JSON_EXTRACT(`data` , '$.permanent') IS NULL
					OR JSON_EXTRACT(`data` , '$.permanent') = 0
					OR JSON_EXTRACT(`data` , '$.permanent') = '0'
					OR JSON_EXTRACT(`data` , '$.permanent') = false
				)
			) OR
			JSON_VALID(`data`) = 0;";
		$sql_args = array( time() - self::DEFAULT_EXPIRY_LENGTH );
		$sql      = apply_filters( 'upserv_clear_nonces_query', $sql, $sql_args );
		$sql_args = apply_filters( 'upserv_clear_nonces_query_args', $sql_args, $sql );
		$result   = $wpdb->query( $wpdb->prepare( $sql, $sql_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $result;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	// API action --------------------------------------------------

	protected static function generate_token_api_response( $payload ) {
		return self::generate_api_response( $payload, false );
	}

	protected static function generate_nonce_api_response( $payload ) {
		return self::generate_api_response( $payload, true );
	}

	protected static function generate_api_response( $payload, $is_nonce ) {
		return self::create_nonce(
			$is_nonce,
			isset( $payload['expiry_length'] ) && is_numeric( $payload['expiry_length'] ) ?
				$payload['expiry_length'] :
				self::DEFAULT_EXPIRY_LENGTH,
			isset( $payload['data'] ) ? $payload['data'] : array(),
			self::NONCE_INFO_ARRAY,
		);
	}

	// Misc. -------------------------------------------------------

	protected static function fetch_nonce( $value ) {
		global $wpdb;

		$nonce = null;

		$row = wp_cache_get( 'nonce_' . $value, 'updatepulse-server', false, $found );

		if ( ! $found ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}upserv_nonce WHERE nonce = %s;", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$value
				)
			);

			wp_cache_set( 'nonce_' . $value, $row, 'updatepulse-server' );
		}

		if ( ! $row ) {
			return $nonce;
		}

		$data      = is_string( $row->data ) ? json_decode( $row->data, true ) : array();
		$permanent = false;

		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( isset( $data['permanent'] ) ) {
			$data['permanent'] = filter_var( $data['permanent'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			$permanent         = (bool) $data['permanent'];
		}

		if ( $row->expiry < time() && ! $permanent ) {
			$row->nonce = apply_filters(
				'upserv_expire_nonce',
				null,
				$row->nonce,
				$row->true_nonce,
				$row->expiry,
				$data,
				$row
			);
		}

		$delete_nonce = apply_filters(
			'upserv_delete_nonce',
			$row->true_nonce || null === $row->nonce,
			$row->true_nonce,
			$row->expiry,
			$data,
			$row
		);

		if ( $delete_nonce ) {
			self::delete_nonce( $value );
		}

		$nonce = apply_filters( 'upserv_fetch_nonce', $row->nonce, $row->true_nonce, $row->expiry, $data, $row );

		return $nonce;
	}

	protected static function store_nonce( $nonce, $true_nonce, $expiry, $data ) {
		global $wpdb;

		$data   = array(
			'nonce'      => $nonce,
			'true_nonce' => (bool) $true_nonce,
			'expiry'     => $expiry,
			'data'       => $data,
		);
		$result = $wpdb->insert( "{$wpdb->prefix}upserv_nonce", $data );

		if ( (bool) $result ) {
			return $data;
		}

		return false;
	}

	protected static function generate_id() {
		require_once ABSPATH . 'wp-includes/class-phpass.php';

		$hasher = new PasswordHash( 8, false );

		return md5( $hasher->get_random_bytes( 100, false ) );
	}

	protected static function authorize() {
		$sign         = false;
		$key_id       = false;
		$timestamp    = 0;
		$auth         = false;
		$credentials  = array();
		$current_time = time();

		if ( ! empty( $_SERVER['HTTP_X_UPDATEPULSE_API_SIGNATURE'] ) ) {
			$sign = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_UPDATEPULSE_API_SIGNATURE'] ) );
		} else {
			global $wp;

			if (
				isset( $wp->query_vars['api_signature'] ) &&
				is_string( $wp->query_vars['api_signature'] ) &&
				! empty( $wp->query_vars['api_signature'] )
			) {
				$sign = $wp->query_vars['api_signature'];
			}
		}

		if ( ! empty( $_SERVER['HTTP_X_UPDATEPULSE_API_CREDENTIALS'] ) ) {
			$credentials = explode(
				'|',
				sanitize_text_field(
					wp_unslash( $_SERVER['HTTP_X_UPDATEPULSE_API_CREDENTIALS'] )
				)
			);
		} else {
			global $wp;

			if (
				isset( $wp->query_vars['api_credentials'] ) &&
				is_string( $wp->query_vars['api_credentials'] ) &&
				! empty( $wp->query_vars['api_credentials'] )
			) {
				$credentials = explode( '|', $wp->query_vars['api_credentials'] );
			}
		}

		if ( 2 === count( $credentials ) ) {
			$timestamp = intval( reset( $credentials ) );
			$key_id    = end( $credentials );
		}

		$validity = (bool) ( constant( 'WP_DEBUG' ) ) ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS;

		if ( $current_time < $timestamp || $timestamp < ( $current_time - $validity ) ) {
			$timestamp = false;
		}

		if ( $sign && $timestamp && $key_id && isset( self::$private_keys[ $key_id ] ) ) {
			$payload = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$values  = upserv_build_nonce_api_signature(
				$key_id,
				self::$private_keys[ $key_id ]['key'],
				$timestamp,
				$payload
			);
			$auth    = hash_equals( $values['signature'], $sign );
		}

		return apply_filters(
			'upserv_nonce_authorize',
			$auth,
			array(
				'credentials' => $timestamp . '/' . $key_id,
				'signature'   => $sign,
			),
			self::$private_keys
		);
	}
}
