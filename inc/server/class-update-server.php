<?php

namespace Anyape\UpdatePulse\Server\Server;

defined( 'ABSPATH' ) || exit; // Exit if accessed directly

use Wpup_UpdateServer;
use Wpup_Package;
use WshWordPressPackageParser_Extended;
use Wpup_FileCache;
use Wpup_Package_Extended;
use Wpup_Request;
use DateTime;
use DateTimeZone;
use WP_Error;
use Exception;
use Anyape\UpdatePulse\Server\Manager\Data_Manager;
use Anyape\UpdatePulse\Server\Manager\Zip_Package_Manager;

class Update_Server extends Wpup_UpdateServer {

	const LOCK_REMOTE_UPDATE_SEC = 10;

	protected $server_directory;
	protected $use_remote_repository;
	protected $repository_service_url;
	protected $repository_branch;
	protected $repository_credentials;
	protected $repository_service_self_hosted;
	protected $update_checker;
	protected $type;
	protected $filter_packages_file_content;
	protected $license_key;
	protected $license_signature;

	public function __construct(
		$use_remote_repository,
		$server_url,
		$server_directory = null,
		$repository_service_url = null,
		$repository_branch = 'master',
		$repository_credentials = null,
		$repository_service_self_hosted = false
	) {
		parent::__construct( $server_url, untrailingslashit( $server_directory ) );

		$this->use_remote_repository          = $use_remote_repository;
		$this->server_directory               = $server_directory;
		$this->repository_service_self_hosted = $repository_service_self_hosted;
		$this->repository_service_url         = $repository_service_url;
		$this->repository_branch              = $repository_branch;
		$this->repository_credentials         = $repository_credentials;
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// Misc. -------------------------------------------------------

	public function get_repository_service_url() {
		return $this->repository_service_url;
	}

	public function pre_filter_package_info( $info, $api, $ref ) {
		$abort        = true;
		$_file        = apply_filters( 'upserv_filter_packages_filename', 'updatepulse.json' );
		$file_content = $api->getRemoteFile( $_file, $ref );

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
				$server_url_parts = explode( '/', untrailingslashit( $this->serverUrl ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

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
					$info = $this->update_checker->requestInfo();

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
		$type = $type ? ucfirst( $type ) : false;

		if ( 'Plugin' === $type || 'Theme' === $type || 'Generic' === $type ) {
			$this->type = $type;
		}
	}

	public function check_remote_package_update( $slug ) {
		do_action( 'upserv_check_remote_update', $slug );

		$needs_update  = true;
		$local_package = $this->findPackage( $slug );

		if ( $local_package instanceof Wpup_Package ) {
			$package_path = $local_package->getFileName();
			$local_meta   = WshWordPressPackageParser_Extended::parsePackage( $package_path, true );
			$local_meta   = apply_filters(
				'upserv_check_remote_package_update_local_meta',
				$local_meta,
				$local_package,
				$slug
			);

			if ( ! $local_meta ) {
				$needs_update = apply_filters(
					'upserv_check_remote_package_update_no_local_meta_needs_update',
					$needs_update,
					$local_package,
					$slug
				);

				return $needs_update;
			}

			$local_info = array(
				'type'         => $local_meta['type'],
				'version'      => $local_meta['header']['Version'],
				'main_file'    => $local_meta['pluginFile'],
				'download_url' => '',
			);

			$this->set_type( $local_info['type'] );

			if ( 'Plugin' === $this->type || 'Theme' === $this->type || 'Generic' === $this->type ) {
				$this->init_update_checker( $slug );

				$remote_info = $this->update_checker->requestInfo();

				if ( $remote_info && ! is_wp_error( $remote_info ) ) {
					$needs_update = version_compare( $remote_info['version'], $local_info['version'], '>' );
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

		$package_path = trailingslashit( $this->packageDirectory ) . $slug . '.zip'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$result       = false;
		$type         = false;
		$cache_key    = false;

		if ( $wp_filesystem->is_file( $package_path ) ) {
			$cache_key = 'metadata-b64-' . $slug . '-'
				. md5(
					$package_path . '|'
					. filesize( $package_path ) . '|'
					. filemtime( $package_path )
				);

			$parsed_info = WshWordPressPackageParser_Extended::parsePackage( $package_path, true );
			$type        = ucfirst( $parsed_info['type'] );
			$result      = $wp_filesystem->delete( $package_path );
		}

		$result = apply_filters( 'upserv_remove_package_result', $result, $type, $slug );

		if ( $result && $cache_key ) {

			if ( ! $this->cache ) {
				$this->cache = new Wpup_FileCache( Data_Manager::get_data_dir( 'cache' ) );
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

	// Overrides ---------------------------------------------------

	protected function initRequest( $query = null, $headers = null ) {
		$request = parent::initRequest( $query, $headers );

		if ( ! upserv_is_package_whitelisted( $request->slug ) ) {
			$this->exitWithError( 'Invalid package.', 404 );
		}

		if ( $request->param( 'type' ) ) {
			$request->type = $request->param( 'type' );
			$this->type    = ucfirst( $request->type );
		}

		$request->token = $request->param( 'token' );

		if ( $request->param( 'license_key' ) ) {
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
		}

		return $request;
	}

	protected function checkAuthorization( $request ) {
		parent::checkAuthorization( $request );

		if (
			'download' === $request->action &&
			! upserv_validate_nonce( $request->token )
		) {
			$message = __( 'The download URL token has expired.', 'updatepulse-server' );

			$this->exitWithError( $message, 403 );
		}

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
			$this->exitWithError( 'Invalid license key or signature.', 403 );
		}
	}

	protected function generateDownloadUrl( Wpup_Package $package ) {
		$metadata = $package->getMetadata();

		$this->set_type( $metadata['type'] );

		$query = array(
			'action'      => 'download',
			'package_id'  => $package->slug,
			'update_type' => $this->type,
		);

		if ( upserv_is_package_require_license( $package->slug ) ) {
			$query['token']             = upserv_create_nonce( true, DAY_IN_SECONDS / 2 );
			$query['license_key']       = $this->license_key;
			$query['license_signature'] = $this->license_signature;
		} else {
			$query['token'] = upserv_create_nonce();
		}

		return self::addQueryArg( $query, $this->serverUrl ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	protected function actionDownload( Wpup_Request $request ) {
		do_action( 'upserv_update_server_action_download', $request );

		$handled = apply_filters( 'upserv_update_server_action_download_handled', false, $request );

		if ( ! $handled ) {
			parent::actionDownload( $request );
		}
	}

	protected function findPackage( $slug, $check_remote = true ) {
		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $this->cache ) {
			$this->cache = new Wpup_FileCache( Data_Manager::get_data_dir( 'cache' ) );
		}

		$safe_slug = preg_replace( '@[^a-z0-9\-_\.,+!]@i', '', $slug );
		$cache_key = 'metadata-b64-' . $safe_slug . '-nocheck';
		$package   = false;

		if ( $this->cache->get( $cache_key ) ) {
			return $package;
		}

		$is_package_ready = false;
		$filename         = trailingslashit( $this->packageDirectory ) . $safe_slug . '.zip'; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$save_to_local    = apply_filters(
			'upserv_save_remote_to_local',
			! $wp_filesystem->is_file( $filename ) || ! $wp_filesystem->is_readable( $filename ),
			$safe_slug,
			$filename,
			$check_remote
		);

		if ( $save_to_local ) {

			if ( $this->use_remote_repository && $this->repository_service_url ) {

				if ( $check_remote ) {
					$is_package_ready = $this->save_remote_package_to_local( $safe_slug );
				}
			}

			if ( true === $is_package_ready ) {
				return $this->findPackage( $slug, false );
			}
		}

		if ( ! is_bool( $is_package_ready ) ) {
			$request_info = $is_package_ready;

			if ( ! $this->cache->get( $cache_key ) ) {
				$expiry_lentgh = constant( 'WP_DEBUG' ) ? 30 : MONTH_IN_SECONDS;

				$this->cache->set( $cache_key, true, $expiry_lentgh );

				$date = new DateTime(
					'now + ' . $expiry_lentgh . ' seconds',
					new DateTimeZone( wp_timezone_string() )
				);

				php_log(
					'Package '
					. $safe_slug
					. ' has been marked as ignored.' . "\n"
					. 'The remote server will not be checked again for this package until: '
					. $date->format( 'Y-m-d H:i:s' ) . ' (' . wp_timezone_string() . ')'
					. "\n"
					. 'Result for `requestInfo`:' . "\n" . print_r( $request_info, true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
				);
			}

			return $package;
		}

		try {
			$cached_value = null;

			if ( $wp_filesystem->is_file( $filename ) && $wp_filesystem->is_readable( $filename ) ) {
				$cache_key    = 'metadata-b64-' . $safe_slug . '-'
					. md5( $filename . '|' . filesize( $filename ) . '|' . filemtime( $filename ) );
				$cached_value = $this->cache->get( $cache_key );
			}

			if ( null === $cached_value ) {
				do_action( 'upserv_find_package_no_cache', $safe_slug, $filename, $this->cache );
			}

			$package = Wpup_Package_Extended::fromArchive( $filename, $safe_slug, $this->cache );
		} catch ( Exception $e ) {
			php_log( 'Corrupt archive ' . $filename . ' ; package will not be displayed or delivered' );

			$log  = 'Exception caught: ' . $e->getMessage() . "\n";
			$log .= 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";

			php_log( $log );
		}

		return $package;
	}

	protected function actionGetMetadata( Wpup_Request $request ) {
		$meta = array();

		if ( $request->package ) {
			$meta                 = $request->package->getMetadata();
			$meta['download_url'] = $this->generateDownloadUrl( $request->package );
		} else {
			$meta['error']   = 'invalid_package';
			$meta['message'] = __( 'Invalid package.', 'updatepulse-server' );
		}

		$meta                 = $this->filterMetadata( $meta, $request );
		$meta['time_elapsed'] = sprintf( '%.3f', microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] );

		$this->outputAsJson( $meta );

		exit;
	}

	protected function filterMetadata( $meta, $request ) {
		$meta = parent::filterMetadata( $meta, $request );

		if (
			! isset( $meta['slug'] ) ||
			! upserv_is_package_require_license( $meta['slug'] )
		) {
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
			$meta['download_url'] = self::addQueryArg( $args, $meta['download_url'] );
		} else {
			unset( $meta['download_url'] );
			unset( $meta['license'] );

			$meta['license_error'] = $this->get_license_error( $license );
		}

		return $meta;
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

	protected static function build_update_checker(
		$metadata_url,
		$slug,
		$file_name,
		$type,
		$package_container,
		$self_hosted = false,
	) {

		if ( 'Plugin' !== $type && 'Theme' !== $type && 'Generic' !== $type ) {
			trigger_error( //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				sprintf(
					'Proxuc does not support packages of type %s',
					esc_html( $type )
				),
				E_USER_ERROR
			);
		}

		$service       = null;
		$api_class     = null;
		$checker_class = null;

		if ( $self_hosted ) {
			$service = 'GitLab';
		} else {
			$host                = wp_parse_url( $metadata_url, PHP_URL_HOST );
			$path                = wp_parse_url( $metadata_url, PHP_URL_PATH );
			$username_repo_regex = '@^/?([^/]+?)/([^/#?&]+?)/?$@';

			if ( preg_match( $username_repo_regex, $path ) ) {
				$known_services = array(
					'github.com'    => 'GitHub',
					'bitbucket.org' => 'BitBucket',
					'gitlab.com'    => 'GitLab',
				);

				if ( isset( $known_services[ $host ] ) ) {
					$service = $known_services[ $host ];
				}
			}
		}

		if ( $service ) {
			$checker_class = 'Anyape\ProxyUpdateChecker\Vcs\\' . $type . 'UpdateChecker';
			$api_class     = $service . 'Api';
		} else {
			trigger_error( //phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
				sprintf(
					'Proxuc could not find a supported service for %s',
					esc_html( $metadata_url )
				),
				E_USER_ERROR
			);
		}

		$api_class = 'YahnisElsts\PluginUpdateChecker\v5p3\Vcs\\' . $api_class;
		$params    = array();

		if ( $file_name ) {
			$params = array(
				new $api_class( $metadata_url ),
				$slug,
				$file_name,
				$package_container,
			);
		} else {
			$params = array(
				new $api_class( $metadata_url ),
				$slug,
				$package_container,
			);
		}

		$update_checker = new $checker_class( ...$params );

		return $update_checker;
	}

	protected function init_update_checker( $slug ) {

		if ( $this->update_checker ) {
			return;
		}

		require_once UPSERV_PLUGIN_PATH . 'lib/proxy-update-checker/proxy-update-checker.php';

		$package_file_name = null;

		if ( 'Plugin' === $this->type ) {
			$package_file_name = $slug;
		} elseif ( 'Generic' === $this->type ) {
			$package_file_name = 'updatepulse';
		}

		$this->update_checker = self::build_update_checker(
			trailingslashit( $this->repository_service_url ) . $slug,
			$slug,
			$package_file_name,
			$this->type,
			$this->packageDirectory, // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$this->repository_service_self_hosted
		);

		if ( $this->update_checker ) {

			if ( $this->repository_credentials ) {
				$this->update_checker->setAuthentication( $this->repository_credentials );
			}

			if ( $this->repository_branch ) {
				$this->update_checker->setBranch( $this->repository_branch );
			}
		}

		$this->update_checker = apply_filters(
			'upserv_update_checker',
			$this->update_checker,
			$slug,
			$this->type,
			$this->repository_service_url,
			$this->repository_branch,
			$this->repository_credentials,
			$this->repository_service_self_hosted
		);
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

		if ( is_string( $this->repository_credentials ) ) {
			$params['headers'] = array(
				'Authorization' => 'token ' . $this->repository_credentials,
			);
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
