<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use DateTime;
use DateTimeZone;
use WP_Error;
use Exception;
use Anyape\UpdatePulse\Package_Parser\Parser as Package_Parser;
use Anyape\UpdatePulse\Server\Server\Update\Cache;
use Anyape\UpdatePulse\Server\Server\Update\Package;
use Anyape\UpdatePulse\Server\Server\Update\Request;
use Anyape\UpdatePulse\Server\Server\Update\Headers;
use Anyape\UpdatePulse\Server\Server\Update\Invalid_Package_Exception;
use Anyape\UpdatePulse\Server\Manager\Data_Manager;
use Anyape\UpdatePulse\Server\Manager\Zip_Package_Manager;
use Anyape\UpdatePulse\Server\Server\License\License_Server;

class Update_Server {

	const LOCK_REMOTE_UPDATE_SEC = 10;

	protected $package_dir;
	protected $log_dir;
	protected $cache;
	protected $server_url;
	protected $timezone;
	protected $server_dir;
	protected $vcs_url;
	protected $branch;
	protected $credentials;
	protected $vcs_type;
	protected $self_hosted;
	protected $update_checker;
	protected $type;
	protected $filter_packages_file_content;
	protected $license_key;
	protected $license_signature;

	public function __construct( $server_url, $server_dir, $vcs_url, $branch, $credentials, $vcs_type, $self_hosted ) {
		$this->server_dir  = $this->normalize_file_path( untrailingslashit( $server_dir ) );
		$this->server_url  = $server_url;
		$this->package_dir = $server_dir . 'packages';
		$this->log_dir     = $server_dir . 'logs';
		$this->cache       = new Cache( $server_dir . 'cache' );
		$this->timezone    = new DateTimeZone( wp_timezone_string() );
		$this->server_dir  = $server_dir;
		$this->vcs_type    = $vcs_type;
		$this->self_hosted = $self_hosted;
		$this->vcs_url     = $vcs_url ? trailingslashit( $vcs_url ) : false;
		$this->branch      = $branch;
		$this->credentials = $credentials;
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	/**
	 * Process an update API request.
	 *
	 * @param array|null $query Query parameters. Defaults to the current GET request parameters.
	 * @param array|null $headers HTTP headers. Defaults to the headers received for the current request.
	 */
	public function handle_request( $query = null, $headers = null ) {
		$request = $this->init_request( $query, $headers );

		$this->log_request( $request );
		$this->load_package_for( $request );
		$this->validate_request( $request );
		$this->check_authorization( $request );
		$this->dispatch( $request );

		exit;
	}

	// Misc. -------------------------------------------------------

	public function get_vcs_url() {
		return $this->vcs_url;
	}

	public function get_branch() {
		return $this->branch;
	}

	public function pre_filter_package_info( $info, $api, $ref ) {
		$abort        = true;
		$_file        = apply_filters( 'upserv_filter_packages_filename', 'updatepulse.json' );
		$file_content = $api->get_remote_file( $_file, $ref );

		$this->filter_packages_file_content = $file_content;

		if ( ! empty( $file_content ) ) {
			$info          = apply_filters( 'upserv_pre_filter_package_info', $info, $file_content );
			$file_contents = json_decode( $file_content, true );

			if (
				is_array( $info ) &&
				! isset( $info['abort_request'] ) &&
				$file_contents &&
				isset( $file_contents['server'] )
			) {
				$url              = filter_var( $file_contents['server'], FILTER_VALIDATE_URL );
				$server_url_parts = explode( '/', untrailingslashit( $this->server_url ) );

				array_pop( $server_url_parts );

				if ( $url ) {
					$info['server'] = trailingslashit( $url );
				}

				$server_url = implode( '/', $server_url_parts );
				$abort      = ! ( $url && trailingslashit( $server_url ) === trailingslashit( $url ) );
			}
		}

		if ( $abort ) {

			if ( is_array( $info ) ) {
				$info['abort_request'] = true;
			} else {
				$info = array( 'abort_request' => true );
			}
		}

		do_action( 'upserv_pre_filter_package_info', $info );

		return $info;
	}

	public function filter_package_info( $info ) {
		$info = apply_filters( 'upserv_filter_package_info', $info, $this->filter_packages_file_content );

		do_action( 'upserv_filter_package_info', $info );

		return $info;
	}

	public function save_remote_package_to_local( $safe_slug, $force = false ) {
		$local_ready = false;

		if ( $force ) {
			self::unlock_update_from_remote( $safe_slug );
		}

		if ( ! self::is_update_from_remote_locked( $safe_slug ) ) {
			self::lock_update_from_remote( $safe_slug );
			$this->init_update_checker( $safe_slug );

			if ( $this->update_checker ) {

				try {
					$info = $this->update_checker->request_info();

					if (
						! apply_filters(
							'upserv_download_remote_package',
							! ( is_array( $info ) && isset( $info['abort_request'] ) && $info['abort_request'] ),
							$safe_slug,
							$this->type,
							$info
						)
					) {
						$this->remove_package( $safe_slug, true );

						do_action( 'upserv_download_remote_package_aborted', $safe_slug, $this->type, $info );

						return $info;
					}

					if ( $info && ! is_wp_error( $info ) ) {
						$this->remove_package( $safe_slug, true );

						$package = $this->download_remote_package( $info['download_url'] );

						do_action( 'upserv_downloaded_remote_package', $package, $info['type'], $safe_slug );

						$package_manager = new Zip_Package_Manager(
							$safe_slug,
							$package,
							Data_Manager::get_data_dir( 'tmp' ),
							Data_Manager::get_data_dir( 'packages' )
						);
						$local_ready     = $package_manager->clean_package();

						do_action(
							'upserv_saved_remote_package_to_local',
							$local_ready,
							$info['type'],
							$safe_slug
						);
					}
				} catch ( Exception $e ) {
					self::unlock_update_from_remote( $safe_slug );

					throw $e;
				}
			}

			self::unlock_update_from_remote( $safe_slug );
		}

		return $local_ready;
	}

	public function set_type( $type ) {
		$type = is_string( $type ) ? ucfirst( strtolower( $type ) ) : false;

		if ( 'Plugin' === $type || 'Theme' === $type || 'Generic' === $type ) {
			$this->type = $type;
		}
	}

	public function check_remote_package_update( $slug ) {
		do_action( 'upserv_check_remote_update', $slug );

		$needs_update  = true;
		$local_package = $this->find_package( $slug );

		if ( $local_package instanceof Package ) {
			$package_path = $local_package->get_filename();
			$meta         = apply_filters(
				'upserv_check_remote_package_update_local_meta',
				Package_Parser::parse_package( $package_path, true ),
				$local_package,
				$slug
			);

			if ( ! $meta ) {
				$needs_update = apply_filters(
					'upserv_check_remote_package_update_no_local_meta_needs_update',
					$needs_update,
					$local_package,
					$slug
				);

				return $needs_update;
			}

			$this->set_type( $meta['type'] );

			if ( 'Plugin' === $this->type || 'Theme' === $this->type || 'Generic' === $this->type ) {
				$this->init_update_checker( $slug );

				$remote_info = $this->update_checker->request_info();

				if ( $remote_info && ! is_wp_error( $remote_info ) ) {
					$needs_update = version_compare( $remote_info['version'], $meta['header']['Version'], '>' );
				} else {
					php_log(
						$remote_info,
						'Invalid value $remote_info for package of type '
						. $this->type . ' and slug ' . $slug
					);
				}
			}
		} else {
			$needs_update = null;
		}

		do_action( 'upserv_checked_remote_package_update', $needs_update, $this->type, $slug );

		return $needs_update;
	}

	public function remove_package( $slug, $force = false ) {
		WP_Filesystem();

		global $wp_filesystem;

		if ( $force ) {
			self::unlock_update_from_remote( $slug );
		}

		if ( self::is_update_from_remote_locked( $slug ) ) {
			return false;
		}

		self::lock_update_from_remote( $slug );

		$package_path = trailingslashit( $this->package_dir ) . $slug . '.zip';
		$result       = false;
		$type         = false;
		$cache_key    = false;

		if ( is_file( $package_path ) ) {
			$cache_key   = Zip_Metadata_Parser::build_cache_key( $slug, $package_path );
			$parsed_info = Package_Parser::parse_package( $package_path, true );
			$type        = ucfirst( $parsed_info['type'] );
			$result      = $wp_filesystem->delete( $package_path );
		}

		$result = apply_filters( 'upserv_remove_package_result', $result, $type, $slug );

		if ( $result && $cache_key ) {

			if ( ! $this->cache ) {
				$this->cache = new Cache( Data_Manager::get_data_dir( 'cache' ) );
			}

			$this->cache->clear( $cache_key );
		}

		do_action( 'upserv_removed_package', $result, $type, $slug );
		self::unlock_update_from_remote( $slug );

		return $result;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	/**
	 * Add one or more query arguments to a URL.
	 * Setting an argument to `null` removes it.
	 *
	 * @param array $args An associative array of query arguments.
	 * @param string $url The old URL. Optional, defaults to the request url without query arguments.
	 * @return string New URL.
	 */
	protected static function add_query_arg( $args, $url ) {

		if ( strpos( $url, '?' ) !== false ) {
			$parts = explode( '?', $url, 2 );
			$base  = $parts[0] . '?';

			parse_str( $parts[1], $query );
		} else {
			$base  = $url . '?';
			$query = array();
		}

		$query = array_merge( $query, $args );

		//Remove null/false arguments.
		$query = array_filter(
			$query,
			function ( $value ) {
				return ( null !== $value ) && ( false !== $value );
			}
		);

		return $base . http_build_query( $query, '', '&' );
	}

	protected function dispatch( $request ) {

		if ( 'get_metadata' === $request->action ) {
			$this->action_get_metadata( $request );
		} elseif ( 'download' === $request->action ) {
			$this->action_download( $request );
		} else {
			$this->exit_with_error( sprintf( 'Invalid action "%s".', htmlentities( $request->action ) ), 400 );
		}
	}

	protected function init_request( $query = null, $headers = null ) {

		if ( null === $query ) {
			$query = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( null === $headers ) {
			$headers = Headers::parse_current();
		}

		$client_ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		$http_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
		$request     = new Request( $query, $headers, $client_ip, $http_method );

		if ( ! upserv_is_package_whitelisted( $request->slug ) && upserv_get_option( 'use_vcs' ) ) {
			$this->exit_with_error( 'Invalid package.', 404 );
		}

		if ( $request->param( 'type' ) ) {
			$request->type = $request->param( 'type' );
			$this->type    = ucfirst( $request->type );
		}

		$request->token = $request->param( 'token' );

		return $this->init_license_request( $request );
	}

	protected function check_authorization( $request ) {

		if (
			'download' === $request->action &&
			! upserv_validate_nonce( $request->token )
		) {
			$message = __( 'The download URL token has expired.', 'updatepulse-server' );

			$this->exit_with_error( $message, 403 );
		}

		$this->check_license_authorization( $request );
	}

	protected function generate_download_url( Package $package ) {
		$metadata = $package->get_metadata();

		$this->set_type( $metadata['type'] );

		$query          = $this->filter_license_download_query(
			array(
				'action'      => 'download',
				'package_id'  => $package->slug,
				'update_type' => $this->type,
			)
		);
		$query['token'] = isset( $query['token'] ) ? $query['token'] : upserv_create_nonce();

		return self::add_query_arg( $query, $this->server_url );
	}

	protected function action_download( Request $request ) {
		do_action( 'upserv_update_server_action_download', $request );

		if ( apply_filters( 'upserv_update_server_action_download_handled', false, $request ) ) {
			return;
		}

		//Required for IE, otherwise Content-Disposition may be ignored.
		if ( ini_get( 'zlib.output_compression' ) ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.IniSet.Risky, WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$package = $request->package;

		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . $package->slug . '.zip' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Content-Length: ' . $package->get_file_size() );

		readfile( $package->get_filename() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
	}

	/**
	 * Basic request validation. Every request must specify an action and a valid package slug.
	 *
	 * @param Wpup_Request $request
	 */
	protected function validate_request( $request ) {

		if ( ! $request->action ) {
			$this->exit_with_error( 'You must specify an action.', 400 );
		}

		if ( ! $request->slug ) {
			$this->exit_with_error( 'You must specify a package slug.', 400 );
		}

		if ( ! $request->package ) {
			$this->exit_with_error( 'Package not found', 404 );
		}
	}

	/**
	 * Load the requested package into the request instance.
	 *
	 * @param Wpup_Request $request
	 */
	protected function load_package_for( $request ) {

		if ( empty( $request->slug ) ) {
			return;
		}

		try {
			$request->package = $this->find_package( $request->slug );
		} catch ( Invalid_Package_Exception $e ) {
			$this->exit_with_error(
				sprintf(
					'Package "%s" exists, but it is not a valid plugin or theme. ' .
					'Make sure it has the right format ( Zip ) and directory structure.',
					htmlentities( $request->slug )
				)
			);

			exit;
		}
	}

	protected function find_package( $slug, $check_remote = true ) {

		if ( ! $this->cache ) {
			$this->cache = new Cache( Data_Manager::get_data_dir( 'cache' ) );
		}

		$safe_slug     = preg_replace( '@[^a-z0-9\-_\.,+!]@i', '', $slug );
		$package       = false;
		$filename      = trailingslashit( $this->package_dir ) . $safe_slug . '.zip';
		$save_to_local = apply_filters(
			'upserv_save_remote_to_local',
			! is_file( $filename ) || ! is_readable( $filename ),
			$safe_slug,
			$filename,
			$check_remote
		);

		if ( upserv_get_option( 'use_vcs' ) && $save_to_local && $check_remote ) {
			$is_package_ready = $this->save_remote_package_to_local( $safe_slug );

			if ( true === $is_package_ready ) {
				return $this->find_package( $slug, false );
			}
		}

		try {
			$cached_value = null;

			if ( is_file( $filename ) && is_readable( $filename ) ) {
				$cache_key    = Zip_Metadata_Parser::build_cache_key( $safe_slug, $filename );
				$cached_value = $this->cache->get( $cache_key );
			}

			if ( null === $cached_value ) {
				do_action( 'upserv_find_package_no_cache', $safe_slug, $filename, $this->cache );
			}

			$package = Package::from_archive( $filename, $safe_slug, $this->cache );
		} catch ( Exception $e ) {
			php_log( 'Corrupt archive ' . $filename . '; package will not be displayed or delivered' );

			$log  = 'Exception caught: ' . $e->getMessage() . "\n";
			$log .= 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";

			php_log( $log );
		}

		return $package;
	}

	protected function action_get_metadata( Request $request ) {
		$meta = array();

		if ( $request->package ) {
			$meta                 = $request->package->get_metadata();
			$meta['download_url'] = $this->generate_download_url( $request->package );
		} else {
			$meta['error']   = 'invalid_package';
			$meta['message'] = __( 'Invalid package.', 'updatepulse-server' );
		}

		$meta                 = $this->filter_metadata( $meta, $request );
		$meta['time_elapsed'] = sprintf( '%.3f', microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] );

		$this->output_as_json( $meta );

		exit;
	}

	protected function filter_metadata( $meta, $request ) {
		$meta = array_filter(
			$meta,
			function ( $value ) {
				return null !== $value;
			}
		);

		if ( ! isset( $meta['slug'] ) ) {
			return $meta;
		}

		return $this->filter_license_metadata( $meta, $request );
	}

	/**
	 * Convert all directory separators to forward slashes.
	 *
	 * @param string $path
	 * @return string
	 */
	protected function normalize_file_path( $path ) {

		if ( ! is_string( $path ) ) {
			return $path;
		}

		return str_replace( array( DIRECTORY_SEPARATOR, '\\' ), '/', $path );
	}

	/**
	 * Log an API request.
	 *
	 * @param Wpup_Request $request
	 */
	protected function log_request( $request ) {
		$log_file = $this->get_log_file_name();
		$handle   = fopen( $log_file, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( $handle && flock( $handle, LOCK_EX ) ) {
			$logged_ip = $request->client_ip;
			$columns   = array(
				'ip'                => $logged_ip,
				'http_method'       => $request->http_method,
				'action'            => $request->param( 'action', '-' ),
				'slug'              => $request->param( 'slug', '-' ),
				'installed_version' => $request->param( 'installed_version', '-' ),
				'wp_version'        => isset( $request->wp_version ) ? $request->wp_version : '-',
				'site_url'          => isset( $request->wp_site_url ) ? $request->wp_site_url : '-',
				'query'             => http_build_query( $request->query, '', '&' ),
			);
			$columns   = $this->escape_log_info( $columns );

			if ( isset( $columns['ip'] ) ) {
				$columns['ip'] = str_pad( $columns['ip'], 15, ' ' );
			}

			if ( isset( $columns['http_method'] ) ) {
				$columns['http_method'] = str_pad( $columns['http_method'], 4, ' ' );
			}

			$date = new DateTime( 'now', $this->timezone );
			$line = $date->format( '[Y-m-d H:i:s O]' ) . ' ' . implode( "\t", $columns ) . "\n";

			fwrite( $handle, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			flock( $handle, LOCK_UN );
		}

		if ( $handle ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * @return string
	 */
	protected function get_log_file_name() {
		$path  = $this->log_dir . '/request';
		$date  = new DateTime( 'now', $this->timezone );
		$path .= '-' . $date->format( 'Y-m-d' );

		return $path . '.log';
	}

	/**
	 * Escapes passed log data so it can be safely written into a plain text file.
	 *
	 * @param string[] $columns List of columns in the log entry.
	 * @return string[] Escaped $columns.
	 */
	protected function escape_log_info( $columns ) {
		return array_map( array( $this, 'escape_log_value' ), $columns );
	}

	/**
	 * Escapes passed value to be safely written into a plain text file.
	 *
	 * @param string|null $value Value to escape.
	 * @return string|null Escaped value.
	 */
	protected function escape_log_value( $value ) {

		if ( ! isset( $value ) ) {
			return null;
		}

		$value = (string) $value;
		$regex = '/[[:^graph:]]/';

		//preg_replace_callback will return NULL if the input contains invalid Unicode sequences,
		//so only enable the Unicode flag if the input encoding looks valid.
		if ( function_exists( 'mb_check_encoding' ) && mb_check_encoding( $value, 'UTF-8' ) ) {
			$regex = $regex . 'u';
		}

		$value = str_replace( '\\', '\\\\', $value );
		$value = preg_replace_callback(
			$regex,
			function ( array $matches ) {
				$length  = strlen( $matches[0] );
				$escaped = '';

				for ( $i = 0; $i < $length; $i++ ) {
					//Convert the character to a hexadecimal escape sequence.
					$hex_code = dechex( ord( $matches[0][ $i ] ) );
					$escaped .= '\x' . strtoupper( str_pad( $hex_code, 2, '0', STR_PAD_LEFT ) );
				}

				return $escaped;
			},
			$value
		);

		return $value;
	}

	protected function exit_with_error( $message = '', $http_status = 500 ) {
		$status_messages = array(
			// This is not a full list of HTTP status messages. We only need the errors.
			// [Client Error 4xx]
			400 => '400 Bad Request',
			401 => '401 Unauthorized',
			402 => '402 Payment Required',
			403 => '403 Forbidden',
			404 => '404 Not Found',
			405 => '405 Method Not Allowed',
			406 => '406 Not Acceptable',
			407 => '407 Proxy Authentication Required',
			408 => '408 Request Timeout',
			409 => '409 Conflict',
			410 => '410 Gone',
			411 => '411 Length Required',
			412 => '412 Precondition Failed',
			413 => '413 Request Entity Too Large',
			414 => '414 Request-URI Too Long',
			415 => '415 Unsupported Media Type',
			416 => '416 Requested Range Not Satisfiable',
			417 => '417 Expectation Failed',
			// [Server Error 5xx]
			500 => '500 Internal Server Error',
			501 => '501 Not Implemented',
			502 => '502 Bad Gateway',
			503 => '503 Service Unavailable',
			504 => '504 Gateway Timeout',
			505 => '505 HTTP Version Not Supported',
		);

		if ( ! isset( $_SERVER['SERVER_PROTOCOL'] ) || '' === $_SERVER['SERVER_PROTOCOL'] ) {
			$protocol = 'HTTP/1.1';
		} else {
			$protocol = $_SERVER['SERVER_PROTOCOL'];
		}

		//Output a HTTP status header.
		if ( isset( $status_messages[ $http_status ] ) ) {
			header( $protocol . ' ' . $status_messages[ $http_status ] );
			$title = $status_messages[ $http_status ];
		} else {
			header( 'X-Ws-Update-Server-Error: ' . $http_status, true, $http_status );
			$title = 'HTTP ' . $http_status;
		}

		if ( '' === $message ) {
			$message = $title;
		}

		//And a basic HTML error message.
		printf(
			'<html>
				<head> <title>%1$s</title> </head>
				<body> <h1>%1$s</h1> <p>%2$s</p> </body>
			 </html>',
			esc_html( $title ),
			esc_html( $message )
		);
		exit;
	}

	/**
	 * Output data as JSON.
	 *
	 * @param mixed $response
	 */
	protected function output_as_json( $response ) {
		header( 'Content-Type: application/json; charset=utf-8' );

		echo wp_json_encode( $response, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// Misc. -------------------------------------------------------

	protected static function unlock_update_from_remote( $slug ) {
		$locks = get_option( 'upserv_update_from_remote_locks' );
		$locks = is_array( $locks ) ? $locks : array();

		if ( array_key_exists( $slug, $locks ) ) {
			unset( $locks[ $slug ] );
		}

		update_option( 'upserv_update_from_remote_locks', $locks );
	}

	protected static function lock_update_from_remote( $slug ) {
		$locks = get_option( 'upserv_update_from_remote_locks', array() );
		$locks = is_array( $locks ) ? $locks : array();

		if ( ! array_key_exists( $slug, $locks ) ) {
			$locks[ $slug ] = time() + self::LOCK_REMOTE_UPDATE_SEC;

			update_option( 'upserv_update_from_remote_locks', $locks );
		}
	}

	protected static function is_update_from_remote_locked( $slug ) {
		$locks     = get_option( 'upserv_update_from_remote_locks' );
		$is_locked = is_array( $locks ) && array_key_exists( $slug, $locks ) && $locks[ $slug ] >= time();

		return $is_locked;
	}

	protected function build_update_checker( $slug, $package_filename ) {
		$repo_url  = trailingslashit( $this->vcs_url ) . $slug;
		$service   = upserv_get_vcs_name( $this->vcs_type, 'edit' );
		$api_class = $service ? 'Anyape\PackageUpdateChecker\Vcs\\' . $service . 'Api' : false;

		if ( ! $api_class ) {
			return false;
		}

		$checker_class = 'Anyape\PackageUpdateChecker\\' . $this->type . 'UpdateChecker';
		$params        = array( new $api_class( $repo_url ), $slug, $this->package_dir );

		if ( $package_filename ) {
			$params[] = $package_filename;
		}

		return new $checker_class( ...$params );
	}

	protected function init_update_checker( $slug ) {
		$this->update_checker = apply_filters(
			'upserv_update_checker',
			$this->update_checker,
			$slug,
			$this->type,
			$this->vcs_url,
			$this->branch,
			$this->credentials,
			$this->self_hosted
		);

		if ( $this->update_checker ) {
			return;
		}

		require_once UPSERV_PLUGIN_PATH . 'lib/package-update-checker/package-update-checker.php';

		$package_filename = null;

		if ( 'Plugin' === $this->type ) {
			$package_filename = $slug;
		} elseif ( 'Generic' === $this->type ) {
			$package_filename = 'updatepulse';
		}

		$this->update_checker = $this->build_update_checker( $slug, $package_filename );

		if ( $this->update_checker ) {

			if ( $this->credentials ) {
				$this->update_checker->set_authentication( $this->credentials );
			}

			$this->update_checker->set_branch( $this->branch );
		}
	}

	protected function download_remote_package( $url, $timeout = 300 ) {

		if ( ! $url ) {
			return new WP_Error( 'http_no_url', __( 'Invalid URL provided.', 'updatepulse-server' ) );
		}

		$local_filename = wp_tempnam( $url );

		if ( ! $local_filename ) {
			return new WP_Error( 'http_no_file', __( 'Could not create temporary file.', 'updatepulse-server' ) );
		}

		$params = array(
			'timeout'  => $timeout,
			'stream'   => true,
			'filename' => $local_filename,
		);

		if ( $this->credentials ) {
			$auth_headers = $this->update_checker->get_vcs_api()->get_authorization_headers();

			if ( $auth_headers ) {
				$params['headers'] = $auth_headers;
			}
		}

		$response = wp_safe_remote_get( $url, $params );

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $local_filename );
			php_log( $response, 'Invalid value for $response' );

			return $response;
		}

		if ( 200 !== abs( intval( wp_remote_retrieve_response_code( $response ) ) ) ) {
			wp_delete_file( $local_filename );

			return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ) );
		}

		$content_md5 = wp_remote_retrieve_header( $response, 'content-md5' );

		if ( $content_md5 ) {
			$md5_check = verify_file_md5( $local_filename, $content_md5 );

			if ( is_wp_error( $md5_check ) ) {
				wp_delete_file( $local_filename );
				php_log( $md5_check, 'Invalid value for $md5_check' );

				return $md5_check;
			}
		}

		return $local_filename;
	}

	// Licenses -------------------------------------------------------

	protected function init_license_request( $request ) {

		if ( ! $request->param( 'license_key' ) ) {
			return $request;
		}

		$result = false;

		if ( $request->param( 'licensed_with' ) ) {
			$info = upserv_get_package_info( $request->slug, false );

			if (
				$info &&
				isset( $info['licensed_with'] ) &&
				$request->param( 'licensed_with' ) === $info['licensed_with']
			) {
				$main_package_info = upserv_get_package_info( $info['licensed_with'], false );
				$result            = $this->verify_license_exists(
					$info['licensed_with'],
					$main_package_info['type'],
					$request->param( 'license_key' )
				);
			}
		}

		if ( ! $result ) {
			$result = $this->verify_license_exists(
				$request->slug,
				$request->type,
				$request->param( 'license_key' )
			);
		}

		$request->license_key       = $request->param( 'license_key' );
		$request->license_signature = $request->param( 'license_signature' );
		$request->license           = $result;

		$this->license_key       = $request->license_key;
		$this->license_signature = $request->license_signature;

		return $request;
	}

	protected function filter_license_metadata( $meta, $request ) {

		if ( ! upserv_is_package_require_license( $meta['slug'] ) ) {
			return $meta;
		}

		$license           = $request->license;
		$license_signature = $request->license_signature;

		if ( is_object( $license ) || is_array( $license ) ) {
			$meta['license'] = $this->prepare_license_for_output( $license );
		}

		if (
			apply_filters(
				'upserv_license_valid',
				$this->is_license_valid( $license, $license_signature ),
				$license,
				$license_signature
			)
		) {
			$args                 = array(
				'license_key'       => $request->license_key,
				'license_signature' => $request->license_signature,
			);
			$meta['download_url'] = self::add_query_arg( $args, $meta['download_url'] );
		} else {
			unset( $meta['download_url'] );
			unset( $meta['license'] );

			$meta['license_error'] = $this->get_license_error( $license );
		}

		return $meta;
	}

	protected function filter_license_download_query( $query ) {

		if ( upserv_is_package_require_license( $query['package_id'] ) ) {
			$query['token']             = upserv_create_nonce( true, DAY_IN_SECONDS / 2 );
			$query['license_key']       = $this->license_key;
			$query['license_signature'] = $this->license_signature;
		}

		return $query;
	}

	protected function check_license_authorization( $request ) {

		if ( ! upserv_is_package_require_license( $request->slug ) ) {
			return;
		}

		$license           = $request->license;
		$license_signature = $request->license_signature;
		$valid             = $this->is_license_valid( $license, $license_signature );

		if (
			'download' === $request->action &&
			! apply_filters( 'upserv_license_valid', $valid, $license, $license_signature )
		) {
			$this->exit_with_error( 'Invalid license key or signature.', 403 );
		}
	}

	protected function get_license_error( $license ) {

		if ( ! $license ) {
			$error = (object) array();

			return $error;
		}

		if ( ! is_object( $license ) ) {
			$error = (object) array(
				'license_key' => $this->license_key,
			);

			return $error;
		}

		switch ( $license->status ) {
			case 'blocked':
				$error = (object) array(
					'status' => 'blocked',
				);

				return $error;
			case 'expired':
				$error = (object) array(
					'status'      => 'expired',
					'date_expiry' => $license->date_expiry,
				);

				return $error;
			case 'pending':
				$error = (object) array(
					'status' => 'pending',
				);

				return $error;
			default:
				$error = (object) array(
					'status' => 'invalid',
				);

				return $error;
		}
	}

	protected function verify_license_exists( $slug, $type, $license_key ) {
		$license_server = new License_Server();
		$payload        = array( 'license_key' => $license_key );
		$result         = $license_server->read_license( $payload );

		if (
			is_object( $result ) &&
			$slug === $result->package_slug &&
			strtolower( $type ) === strtolower( $result->package_type )
		) {
			$result->result  = 'success';
			$result->message = __( 'License key details retrieved.', 'updatepulse-server' );
		} else {
			$result = false;
		}

		return $result;
	}

	protected function prepare_license_for_output( $license ) {
		$output = json_decode( wp_json_encode( $license ), true );

		unset( $output['id'] );
		unset( $output['hmac_key'] );
		unset( $output['crypto_key'] );
		unset( $output['data'] );
		unset( $output['owner_name'] );
		unset( $output['email'] );
		unset( $output['company_name'] );

		return apply_filters( 'upserv_license_update_server_prepare_license_for_output', $output, $license );
	}

	protected function is_license_valid( $license, $license_signature ) {
		$valid = false;

		if ( is_object( $license ) && ! is_wp_error( $license ) && 'activated' === $license->status ) {

			if ( apply_filters( 'upserv_license_bypass_signature', false, $license ) ) {
				$valid = $this->license_key === $license->license_key;
			} else {
				$license_server = new License_Server();
				$valid          = $this->license_key === $license->license_key &&
					$license_server->is_signature_valid( $license->license_key, $license_signature );
			}
		}

		return $valid;
	}
}
