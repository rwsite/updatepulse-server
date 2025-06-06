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

/**
 * Nonce class
 *
 * @since 1.0.0
 */
class Nonce {

	/**
	 * Default expiry length
	 *
	 * Default time in seconds before a nonce expires.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	const DEFAULT_EXPIRY_LENGTH = MINUTE_IN_SECONDS / 2;
	/**
	 * Nonce only return type
	 *
	 * Constant indicating to return just the nonce string.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	const NONCE_ONLY = 1;
	/**
	 * Nonce info array return type
	 *
	 * Constant indicating to return the nonce with additional information.
	 *
	 * @var int
	 * @since 1.0.0
	 */
	const NONCE_INFO_ARRAY = 2;

	/**
	 * True nonce flag
	 *
	 * Indicates if a nonce is a true nonce.
	 *
	 * @var bool|null
	 * @since 1.0.0
	 */
	protected static $true_nonce;
	/**
	 * Expiry length
	 *
	 * Time in seconds before a nonce expires.
	 *
	 * @var int|null
	 * @since 1.0.0
	 */
	protected static $expiry_length;
	/**
	 * API request flag
	 *
	 * Indicates if the current request is an API request.
	 *
	 * @var bool|null
	 * @since 1.0.0
	 */
	protected static $doing_api_request = null;
	/**
	 * Private keys
	 *
	 * Array of private keys used for authentication.
	 *
	 * @var array|null
	 * @since 1.0.0
	 */
	protected static $private_keys;

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	/**
	 * Activate
	 *
	 * Setup necessary database tables on plugin activation.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		$result = self::maybe_create_or_upgrade_db();

		if ( ! $result ) {
			$error_message = __( 'Failed to create the necessary database table(s).', 'updatepulse-server' );

			die( $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	/**
	 * Deactivate
	 *
	 * Clean up scheduled actions on plugin deactivation.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		Scheduler::get_instance()->unschedule_all_actions( 'upserv_nonce_cleanup' );
	}

	/**
	 * Uninstall
	 *
	 * Placeholder for uninstall logic.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {}

	/**
	 * Initialize scheduler
	 *
	 * Schedule recurring actions for nonce cleanup.
	 *
	 * @since 1.0.0
	 */
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

	/**
	 * Add endpoints
	 *
	 * Add rewrite rules for nonce and token endpoints.
	 *
	 * @since 1.0.0
	 */
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

	/**
	 * Parse request
	 *
	 * Handle incoming requests to the nonce and token endpoints.
	 *
	 * @since 1.0.0
	 */
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

			/**
			 * Filter the payload sent to the Nonce API.
			 *
			 * @param array $payload The payload sent to the Nonce API
			 * @param string $method The api action - `token` or `nonce`
			 */
			$payload = apply_filters( 'upserv_nonce_api_payload', $payload, $method );

			if (
				is_string( $wp->query_vars['action'] ) &&
				method_exists(
					__CLASS__,
					'generate_' . $wp->query_vars['action'] . '_api_response'
				)
			) {
				$method   = 'generate_' . $wp->query_vars['action'] . '_api_response';
				$response = self::$method( $payload );

				if ( $response ) {
					$code                     = 200;
					$response['time_elapsed'] = Utils::get_time_elapsed();
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

		/**
		 * Filter the HTTP response code to be sent by the Nonce API.
		 *
		 * @param string $code The HTTP response code to be sent by the Nonce API
		 * @param array $request_params The request's parameters
		 */
		$code = apply_filters( 'upserv_nonce_api_code', $code, $wp->query_vars );

		/**
		 * Filter the response to be sent by the Nonce API.
		 *
		 * @param array $response The response to be sent by the Nonce API
		 * @param string $code The HTTP response code sent by the Nonce API
		 * @param array $request_params The request's parameters
		 */
		$response = apply_filters( 'upserv_nonce_api_response', $response, $code, $wp->query_vars );

		wp_send_json( $response, $code );
	}

	/**
	 * Add query vars
	 *
	 * Add custom query variables for nonce and token endpoints.
	 *
	 * @param array $query_vars Existing query variables.
	 * @return array Modified query variables.
	 * @since 1.0.0
	 */
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

	// Misc. -------------------------------------------------------

	/**
	 * Create or upgrade database
	 *
	 * Create or upgrade the necessary database tables.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
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

	/**
	 * Register hooks
	 *
	 * Register WordPress hooks for the nonce functionality.
	 *
	 * @since 1.0.0
	 */
	public static function register() {

		if ( ! self::is_doing_api_request() ) {
			add_action( 'upserv_scheduler_init', array( __CLASS__, 'upserv_scheduler_init' ) );
			add_action( 'upserv_nonce_cleanup', array( __CLASS__, 'upserv_nonce_cleanup' ) );
		}

		add_action( 'init', array( __CLASS__, 'add_endpoints' ) );
		add_action( 'parse_request', array( __CLASS__, 'parse_request' ), -99, 0 );

		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ), -99, 1 );
	}

	/**
	 * Initialize authentication
	 *
	 * Initialize the private keys used for authentication.
	 *
	 * @param array $private_keys Array of private keys.
	 * @since 1.0.0
	 */
	public static function init_auth( $private_keys ) {
		self::$private_keys = $private_keys;
	}

	/**
	 * Check if doing API request
	 *
	 * Check if the current request is an API request.
	 *
	 * @return bool True if doing API request, false otherwise.
	 * @since 1.0.0
	 */
	public static function is_doing_api_request() {

		if ( null === self::$doing_api_request ) {
			self::$doing_api_request = Utils::is_url_subpath_match( '/^updatepulse-server-(nonce|token)$/' );
		}

		return self::$doing_api_request;
	}

	/**
	 * Create nonce
	 *
	 * Create a new nonce.
	 *
	 * @param bool $true_nonce Indicates if the nonce is a true nonce.
	 * @param int $expiry_length Time in seconds before the nonce expires.
	 * @param array $data Additional data to store with the nonce.
	 * @param int $return_type Return type (nonce only or nonce info array).
	 * @param bool $store Indicates if the nonce should be stored in the database.
	 * @return mixed The nonce or nonce info array.
	 * @since 1.0.0
	 */
	public static function create_nonce(
		$true_nonce = true,
		$expiry_length = self::DEFAULT_EXPIRY_LENGTH,
		$data = array(),
		$return_type = self::NONCE_ONLY,
		$store = true
	) {
		/**
		 * Filter the value of the nonce before it is created; if $nonce_value is truthy,
		 * the value is used as nonce and the default generation algorithm is bypassed;
		 * developers must respect the $return_type.
		 *
		 * @param bool|string|array $nonce_value The value of the nonce before it is created - if truthy, the nonce is considered created with this value
		 * @param bool $true_nonce Whether the nonce is a true, one-time-use nonce
		 * @param int $expiry_length The expiry length of the nonce in seconds
		 * @param array $data Data to store along the nonce
		 * @param int $return_type UPServ_Nonce::NONCE_ONLY or UPServ_Nonce::NONCE_INFO_ARRAY
		 */
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

	/**
	 * Get nonce expiry
	 *
	 * Get the expiry time of a nonce.
	 *
	 * @param string $nonce The nonce string.
	 * @return int The expiry time in seconds.
	 * @since 1.0.0
	 */
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

	/**
	 * Get nonce data
	 *
	 * Get the data associated with a nonce.
	 *
	 * @param string $nonce The nonce string.
	 * @return array The nonce data.
	 * @since 1.0.0
	 */
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

	/**
	 * Validate nonce
	 *
	 * Validate a nonce.
	 *
	 * @param string $value The nonce string.
	 * @return bool True if the nonce is valid, false otherwise.
	 * @since 1.0.0
	 */
	public static function validate_nonce( $value ) {

		if ( empty( $value ) ) {
			return false;
		}

		$nonce = self::fetch_nonce( $value );
		$valid = ( $nonce === $value );

		return $valid;
	}

	/**
	 * Delete nonce
	 *
	 * Delete a nonce from the database.
	 *
	 * @param string $value The nonce string.
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public static function delete_nonce( $value ) {
		global $wpdb;

		$result = $wpdb->delete( "{$wpdb->prefix}upserv_nonce", array( 'nonce' => $value ) );

		wp_cache_delete( 'nonce_' . $value, 'updatepulse-server' );

		return (bool) $result;
	}

	/**
	 * Nonce cleanup
	 *
	 * Clean up expired nonces from the database.
	 *
	 * @since 1.0.0
	 */
	public static function upserv_nonce_cleanup() {

		if ( defined( 'WP_SETUP_CONFIG' ) || defined( 'WP_INSTALLING' ) ) {
			return;
		}

		global $wpdb;

		$sql      = "DELETE FROM {$wpdb->prefix}upserv_nonce
			WHERE expiry < %d
			AND (
				JSON_VALID(`data`) = 0
				OR (
					JSON_VALID(`data`) = 1
					AND (
						JSON_EXTRACT(`data` , '$.permanent') IS NULL
						OR JSON_EXTRACT(`data` , '$.permanent') = 0
						OR JSON_EXTRACT(`data` , '$.permanent') = '0'
						OR JSON_EXTRACT(`data` , '$.permanent') = false
					)
				)
			);";
		$sql_args = array( time() - self::DEFAULT_EXPIRY_LENGTH );

		/**
		 * Filter the SQL query used to clear expired nonces.
		 *
		 * @param string $sql The SQL query used to clear expired nonces
		 * @param array $sql_args The arguments passed to the SQL query used to clear expired nonces
		 */
		$sql = apply_filters( 'upserv_clear_nonces_query', $sql, $sql_args );

		/**
		 * Filter the arguments passed to the SQL query used to clear expired nonces.
		 *
		 * @param array $sql_args The arguments passed to the SQL query used to clear expired nonces
		 * @param string $sql The SQL query used to clear expired nonces
		 */
		$sql_args = apply_filters( 'upserv_clear_nonces_query_args', $sql_args, $sql );
		$result   = $wpdb->query( $wpdb->prepare( $sql, $sql_args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (bool) $result;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	// API action --------------------------------------------------

	/**
	 * Generate token API response
	 *
	 * Generate a response for the token API endpoint.
	 *
	 * @param array $payload The request payload.
	 * @return array The API response.
	 * @since 1.0.0
	 */
	protected static function generate_token_api_response( $payload ) {
		return self::generate_api_response( $payload, false );
	}

	/**
	 * Generate nonce API response
	 *
	 * Generate a response for the nonce API endpoint.
	 *
	 * @param array $payload The request payload.
	 * @return array The API response.
	 * @since 1.0.0
	 */
	protected static function generate_nonce_api_response( $payload ) {
		return self::generate_api_response( $payload, true );
	}

	/**
	 * Generate API response
	 *
	 * Generate a response for the API endpoint.
	 *
	 * @param array $payload The request payload.
	 * @param bool $is_nonce Indicates if the response is for a nonce.
	 * @return array The API response.
	 * @since 1.0.0
	 */
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

	/**
	 * Fetch nonce
	 *
	 * Fetch a nonce from the database.
	 *
	 * @param string $value The nonce string.
	 * @return string|null The nonce or null if not found.
	 * @since 1.0.0
	 */
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
			/**
			 * Filter whether to consider the nonce has expired.
			 *
			 * @param bool $expire_nonce Whether to consider the nonce has expired
			 * @param string $nonce_value The value of the nonce
			 * @param bool $true_nonce Whether the nonce is a true, one-time-use nonce
			 * @param int $expiry The timestamp at which the nonce expires
			 * @param array $data Data stored along the nonce
			 * @param object $row The database record corresponding to the nonce
			 */
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

		/**
		 * Filter whether to delete the nonce.
		 *
		 * @param bool $delete Whether to delete the nonce
		 * @param string $nonce_value The value of the nonce
		 * @param bool $true_nonce Whether the nonce is a true, one-time-use nonce
		 * @param int $expiry The timestamp at which the nonce expires
		 * @param array $data Data stored along the nonce
		 * @param object $row The database record corresponding to the nonce
		 */
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

		/**
		 * Filter the value of the nonce after it has been fetched from the database.
		 *
		 * @param string $nonce_value The value of the nonce after it has been fetched from the database
		 * @param bool $true_nonce Whether the nonce is a true, one-time-use nonce
		 * @param int $expiry The timestamp at which the nonce expires
		 * @param array $data Data stored along the nonce
		 * @param object $row The database record corresponding to the nonce
		 */
		$nonce = apply_filters( 'upserv_fetch_nonce', $row->nonce, $row->true_nonce, $row->expiry, $data, $row );

		return $nonce;
	}

	/**
	 * Store nonce
	 *
	 * Store a nonce in the database.
	 *
	 * @param string $nonce The nonce string.
	 * @param bool $true_nonce Indicates if the nonce is a true nonce.
	 * @param int $expiry The expiry time in seconds.
	 * @param string $data The nonce data.
	 * @return array|false The stored nonce data or false on failure.
	 * @since 1.0.0
	 */
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

	/**
	 * Generate ID
	 *
	 * Generate a unique ID.
	 *
	 * @return string The generated ID.
	 * @since 1.0.0
	 */
	protected static function generate_id() {
		require_once ABSPATH . 'wp-includes/class-phpass.php';

		$hasher = new PasswordHash( 8, false );

		return md5( $hasher->get_random_bytes( 100, false ) );
	}

	/**
	 * Authorize request
	 *
	 * Authorize the incoming request using the provided credentials and signature.
	 *
	 * @return bool True if the request is authorized, false otherwise.
	 * @since 1.0.0
	 */
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

		/**
		 * Filter whether the request for a nonce is authorized.
		 *
		 * @param bool $authorized Whether the request is authorized
		 * @param string $received_key The key use to attempt the authorization
		 * @param array $private_auth_keys The valid authorization keys
		 */
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
