<?php

namespace Anyape\UpdatePulse\Server\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Exception;
use WP_Error;
use PhpS3\PhpS3;
use PhpS3\PhpS3Exception;
use Anyape\UpdatePulse\Server\API\Package_API;
use Anyape\UpdatePulse\Server\Server\Update\Zip_Metadata_Parser;
use Anyape\UpdatePulse\Server\Server\Update\Invalid_Package_Exception;
use Anyape\UpdatePulse\Server\Server\Update\Cache;
use Anyape\UpdatePulse\Server\Server\Update\Package;
use Anyape\UpdatePulse\Package_Parser\Parser;

class Cloud_Storage_Manager {

	protected static $instance;
	protected static $config;
	protected static $cloud_storage;
	protected static $virtual_dir;
	protected static $hooks = array();

	protected $doing_redirect = false;

	public const DOWNLOAD_URL_LIFETIME = MINUTE_IN_SECONDS;

	public function __construct( $init_hooks = false ) {
		$config = self::get_config();

		if ( ! self::$cloud_storage instanceof PhpS3 ) {
			self::$cloud_storage = new PhpS3(
				$config['access_key'],
				$config['secret_key'],
				true,
				$config['endpoint'],
				$config['region'],
			);

			self::$cloud_storage->setExceptions();

			self::$virtual_dir = apply_filters( 'upserv_cloud_storage_virtual_dir', 'updatepulse-packages' );
		}

		if ( $init_hooks ) {

			if ( ! upserv_is_doing_api_request() ) {
				add_action( 'wp_ajax_upserv_cloud_storage_test', array( $this, 'cloud_storage_test' ), 10, 0 );
				add_action( 'upserv_package_options_updated', array( $this, 'upserv_package_options_updated' ), 10, 0 );
				add_action( 'upserv_template_package_manager_option_before_miscellaneous', array( $this, 'upserv_template_package_manager_option_before_miscellaneous' ), 10, 0 );

				add_filter( 'upserv_admin_scripts', array( $this, 'upserv_admin_scripts' ), 10, 1 );
				add_filter( 'upserv_submitted_package_config', array( $this, 'upserv_submitted_package_config' ), 10, 1 );
				add_filter( 'upserv_package_option_update', array( $this, 'upserv_package_option_update' ), 10, 4 );
			}

			if ( $config['use_cloud_storage'] ) {
				$this->add_hooks();
			} else {
				$this->remove_hooks();
			}
		}
	}

	protected function add_hooks() {

		if ( ! empty( self::$hooks ) ) {
			return;
		}

		self::$hooks = array(
			'actions' => array(
				array( 'upserv_saved_remote_package_to_local', 'upserv_saved_remote_package_to_local', 10, 3 ),
				array( 'upserv_find_package_no_cache', 'upserv_find_package_no_cache', 10, 3 ),
				array( 'upserv_update_server_action_download', 'upserv_update_server_action_download', 10, 1 ),
				array( 'upserv_after_packages_download', 'upserv_after_packages_download', 10, 2 ),
				array( 'upserv_before_packages_download_repack', 'upserv_before_packages_download_repack', 10, 3 ),
				array( 'upserv_before_packages_download', 'upserv_before_packages_download', 10, 3 ),
				array( 'upserv_did_manual_upload_package', 'upserv_did_manual_upload_package', 10, 3 ),
				array( 'upserv_package_api_request', 'upserv_package_api_request', 10, 2 ),
			),
			'filters' => array(
				array( 'upserv_save_remote_to_local', 'upserv_save_remote_to_local', 10, 4 ),
				array( 'upserv_check_remote_package_update_local_meta', 'upserv_check_remote_package_update_local_meta', 10, 3 ),
				array( 'upserv_zip_metadata_parser_cache_key', 'upserv_zip_metadata_parser_cache_key', 10, 3 ),
				array( 'upserv_package_manager_get_batch_package_info', 'upserv_package_manager_get_batch_package_info', 10, 2 ),
				array( 'upserv_package_manager_get_package_info', 'upserv_package_manager_get_package_info', 10, 2 ),
				array( 'upserv_update_server_action_download_handled', 'upserv_update_server_action_download_handled', 10, 1 ),
				array( 'upserv_remote_sources_manager_get_package_slugs', 'upserv_remote_sources_manager_get_package_slugs', 10, 4 ),
				array( 'upserv_remove_package_result', 'upserv_remove_package_result', 10, 3 ),
				array( 'upserv_delete_packages_bulk_paths', 'upserv_delete_packages_bulk_paths', 10, 1 ),
				array( 'upserv_webhook_package_exists', 'upserv_webhook_package_exists', 10, 3 ),
				array( 'upserv_get_admin_template_args', 'upserv_get_admin_template_args', 10, 2 ),
				array( 'upserv_is_package_whitelisted', 'upserv_is_package_whitelisted', 10, 2 ),
				array( 'upserv_whitelist_package_data', 'upserv_whitelist_package_data', 10, 2 ),
			),
		);

		// Register actions.
		foreach ( self::$hooks['actions'] as $hook ) {
			$accepted_args = isset( $hook[3] ) ? $hook[3] : 1;
			add_action( $hook[0], array( $this, $hook[1] ), $hook[2], $accepted_args );
		}

		// Register filters.
		foreach ( self::$hooks['filters'] as $hook ) {
			$accepted_args = isset( $hook[3] ) ? $hook[3] : 1;
			add_filter( $hook[0], array( $this, $hook[1] ), $hook[2], $accepted_args );
		}
	}

	protected function remove_hooks() {

		if ( empty( self::$hooks ) ) {
			return;
		}

		// Remove actions.
		foreach ( self::$hooks['actions'] as $hook ) {
			$accepted_args = isset( $hook[3] ) ? $hook[3] : 1;
			remove_action( $hook[0], array( $this, $hook[1] ), $hook[2], $accepted_args );
		}

		// Remove filters.
		foreach ( self::$hooks['filters'] as $hook ) {
			$accepted_args = isset( $hook[3] ) ? $hook[3] : 1;
			remove_filter( $hook[0], array( $this, $hook[1] ), $hook[2], $accepted_args );
		}

		self::$hooks = array();
	}

	public static function get_config( $force = false ) {

		if ( $force || ! self::$config ) {
			$config = array(
				'use_cloud_storage' => get_option( 'upserv_use_cloud_storage' ),
				'access_key'        => get_option( 'upserv_cloud_storage_access_key' ),
				'secret_key'        => get_option( 'upserv_cloud_storage_secret_key' ),
				'endpoint'          => get_option( 'upserv_cloud_storage_endpoint' ),
				'storage_unit'      => get_option( 'upserv_cloud_storage_unit' ),
				'region'            => get_option( 'upserv_cloud_storage_region' ),
			);

			self::$config = $config;
		}

		return apply_filters( 'upserv_cloud_storage_api_config', self::$config );
	}

	public static function get_instance() {

		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function upserv_admin_scripts( $scripts ) {
		$scripts['cloud-storage'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'js/admin/cloud-storage' . upserv_assets_suffix() . '.js',
			'uri'  => UPSERV_PLUGIN_URL . 'js/admin/cloud-storage' . upserv_assets_suffix() . '.js',
			'deps' => array( 'jquery' ),
		);

		return $scripts;
	}

	public function upserv_submitted_package_config( $config ) {
		$config = array_merge(
			$config,
			array(
				'upserv_use_cloud_storage'        => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_use_cloud_storage', FILTER_VALIDATE_BOOLEAN ),
					'display_name'            => __( 'Use Cloud Storage', 'updatepulse-server' ),
					'failure_display_message' => __( 'Something went wrong', 'updatepulse-server' ),
					'condition'               => 'boolean',
				),
				'upserv_cloud_storage_access_key' => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_cloud_storage_access_key', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Cloud Storage Access Key', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'condition'               => 'use-cloud-storage',
				),
				'upserv_cloud_storage_secret_key' => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_cloud_storage_secret_key', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Cloud Storage Secret Key', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'condition'               => 'use-cloud-storage',
				),
				'upserv_cloud_storage_endpoint'   => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_cloud_storage_endpoint', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Cloud Storage Endpoint', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'condition'               => 'use-cloud-storage',
				),
				'upserv_cloud_storage_unit'       => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_cloud_storage_unit', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Cloud Storage Unit', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'condition'               => 'use-cloud-storage',
				),
				'upserv_cloud_storage_region'     => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_cloud_storage_region', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Cloud Storage Region', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'condition'               => 'use-cloud-storage',
				),
			)
		);

		return $config;
	}

	public function upserv_package_option_update( $condition, $option_name, $option_info, $options ) {

		if ( 'use-cloud-storage' === $option_info['condition'] ) {

			if (
				'upserv_cloud_storage_region' === $option_name &&
				empty( $option_info['value'] )
			) {
				$condition = true;
			} elseif ( $options['upserv_use_cloud_storage']['value'] ) {
				$condition = ! empty( $option_info['value'] );
			} else {

				if ( 'upserv_cloud_storage_endpoint' === $option_name ) {
					$condition = filter_var( 'http://' . $option_info['value'], FILTER_SANITIZE_URL );
				} else {
					$condition = true;
				}

				$option_info['value'] = '';
			}

			if ( ! $condition ) {
				update_option( 'upserv_use_cloud_storage', false );
			}
		} else {
			$condition = true;
		}

		return $condition;
	}

	public function upserv_template_package_manager_option_before_miscellaneous() {
		upserv_get_admin_template(
			'cloud-storage-options.php',
			array(
				'use_cloud_storage' => get_option( 'upserv_use_cloud_storage' ),
				'virtual_dir'       => self::$virtual_dir,
			)
		);
	}

	public function upserv_remote_sources_manager_get_package_slugs( $slugs ) {
		$slugs    = array();
		$config   = self::get_config();
		$contents = wp_cache_get( 'upserv-getBucket', 'updatepulse-server' );

		if ( false === $contents ) {

			try {
				$contents = self::$cloud_storage->getBucket( $config['storage_unit'], self::$virtual_dir . '/' );

				unset( $contents[ self::$virtual_dir . '/' ] );

				if ( ! empty( $contents ) ) {

					foreach ( $contents as $item ) {
						$slugs[] = str_replace( array( self::$virtual_dir . '/', '.zip' ), array( '', '' ), $item['name'] );
					}
				}
			} catch ( PhpS3Exception $e ) {
				php_log(
					array(
						'error'  => $e->getMessage(),
						'file'   => $e->getFile(),
						'line'   => $e->getLine(),
						'caller' => $e->getTrace()[1],
						'slugs'  => $slugs,
					)
				);
			}
		}

		return $slugs;
	}

	public function upserv_delete_packages_bulk_paths( $package_paths ) {
		$config = self::get_config();

		try {
			$contents = self::$cloud_storage->getBucket( $config['storage_unit'], self::$virtual_dir . '/' );

			unset( $contents[ self::$virtual_dir . '/' ] );

			if ( ! empty( $contents ) ) {

				foreach ( $contents as $item ) {
					$package_paths[] = $item['name'];
				}
			}
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'  => $e->getMessage(),
					'file'   => $e->getFile(),
					'line'   => $e->getLine(),
					'caller' => $e->getTrace()[1],
				)
			);
		}

		return $package_paths;
	}

	public function upserv_webhook_package_exists( $package_exists, $payload, $slug ) {

		if ( null !== $package_exists ) {
			return $package_exists;
		}

		$config = self::get_config();

		try {
			$info = wp_cache_get( $slug . '-getObjectInfo', 'updatepulse-server' );

			if ( false === $info ) {
				$info = self::$cloud_storage->getObjectInfo(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
				);

				wp_cache_set( $slug . '-getObjectInfo', $info, 'updatepulse-server' );
			}

			return null === $info ? $info : (bool) $info;
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'  => $e->getMessage(),
					'file'   => $e->getFile(),
					'line'   => $e->getLine(),
					'caller' => $e->getTrace()[1],
					'slug'   => $slug,
				)
			);

			return $package_exists;
		}
	}

	public function upserv_remove_package_result( $result, $type, $slug ) {
		$config = self::get_config();

		try {
			$info = wp_cache_get( $slug . '-getObjectInfo', 'updatepulse-server' );

			if ( false === $info ) {
				$info = self::$cloud_storage->getObjectInfo(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
				);

				wp_cache_set( $slug . '-getObjectInfo', $info, 'updatepulse-server' );
			}

			$package_directory = Data_Manager::get_data_dir( 'packages' );
			$filename          = $package_directory . $slug . '.zip';
			$cache_key         = self::build_cache_key( $slug, $filename );

			if ( $cache_key ) {
				$cache = new Cache( Data_Manager::get_data_dir( 'cache' ) );

				$cache->clear( $cache_key );
			}

			$result = self::$cloud_storage->deleteObject(
				$config['storage_unit'],
				self::$virtual_dir . '/' . $slug . '.zip'
			);
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'  => $e->getMessage(),
					'file'   => $e->getFile(),
					'line'   => $e->getLine(),
					'caller' => $e->getTrace()[1],
					'slug'   => $slug,
				)
			);
		}

		return $result;
	}

	public function upserv_get_admin_template_args( $args, $template_name ) {
		$template_names = array( 'plugin-packages-page.php', 'plugin-help-page.php', 'plugin-remote-sources-page.php' );

		if ( in_array( $template_name, $template_names, true ) ) {
			$args['packages_dir'] = 'CloudStorageUnit://' . self::$virtual_dir . '/';
		}

		return $args;
	}

	public function cloud_storage_test() {
		$result = array();

		if ( isset( $_REQUEST['nonce'] ) && wp_verify_nonce( $_REQUEST['nonce'], 'upserv_plugin_options' ) ) {
			$data = filter_input( INPUT_POST, 'data', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );

			if ( $data ) {
				$access_key   = $data['upserv_cloud_storage_access_key'];
				$secret_key   = $data['upserv_cloud_storage_secret_key'];
				$endpoint     = $data['upserv_cloud_storage_endpoint'];
				$storage_unit = $data['upserv_cloud_storage_unit'];
				$region       = $data['upserv_cloud_storage_region'];

				self::$cloud_storage->setAuth( $access_key, $secret_key );
				self::$cloud_storage->setEndpoint( $endpoint );
				self::$cloud_storage->setRegion( $region );

				try {
					$storage_units = self::$cloud_storage->listBuckets();

					if ( ! in_array( $storage_unit, $storage_units, true ) ) {
						$result = new WP_Error(
							__METHOD__,
							__( 'Error - Storage Unit not found', 'updatepulse-server' )
						);
					} else {
						$result[] = __( 'Cloud Storage Service was reached sucessfully.', 'updatepulse-server' );

						if ( ! $this->virtual_folder_exists( self::$virtual_dir ) ) {
							$created  = $this->create_virtual_folder( self::$virtual_dir, $storage_unit );
							$result[] = $created ?
								sprintf(
									// translators: %s is the virtual folder
									esc_html__( 'Virtual folder "%s" was created successfully.', 'updatepulse-server' ),
									self::$virtual_dir,
								) :
								sprintf(
									// translators: %s is the virtual folder
									esc_html__( 'WARNING: Unable to create Virtual folder "%s". The Cloud Storage feature may not work as expected. Try to create it manually and test again.', 'updatepulse-server' ),
									self::$virtual_dir,
								);
						} else {
							$result[] = sprintf(
								// translators: %s is the virtual folder
								esc_html__( 'Virtual folder "%s" found.', 'updatepulse-server' ),
								self::$virtual_dir,
							);
						}
					}
				} catch ( PhpS3Exception $e ) {
					$result = new WP_Error(
						__METHOD__ . ' => PhpS3Exception',
						$e->getMessage()
					);

					$result->add( __METHOD__ . ' => LF', '' );
					$result->add( __METHOD__, __( 'An error occured when attempting to communicate with the Cloud Storage Service. Please check all the settings and try again.', 'updatepulse-server' ) );
				}
			} else {
				$result = new WP_Error(
					__METHOD__,
					__( 'Error - Received invalid data ; please reload the page and try again.', 'updatepulse-server' )
				);
			}
		}

		if ( ! is_wp_error( $result ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	public function upserv_package_options_updated() {
		$config = self::get_config( true );

		if ( ! $config['use_cloud_storage'] ) {
			$this->remove_hooks();

			return;
		}

		$this->add_hooks();

		try {

			if ( ! $this->virtual_folder_exists( self::$virtual_dir ) ) {

				if ( ! $this->create_virtual_folder( self::$virtual_dir ) ) {
					php_log(
						sprintf(
							// translators: %s is the virtual folder
							esc_html__( 'WARNING: Unable to create Virtual folder "%s". The Cloud Storage feature may not work as expected. Try to create it manually and test again.', 'updatepulse-server' ),
							self::$virtual_dir,
						)
					);
				}
			}
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'  => $e->getMessage(),
					'file'   => $e->getFile(),
					'line'   => $e->getLine(),
					'caller' => $e->getTrace()[1],
				)
			);
		}
	}

	public function upserv_check_remote_package_update_local_meta( $local_meta, $local_package, $slug ) {

		if ( ! $local_meta ) {
			$config = self::get_config();

			try {
				$filename = $local_package->getFileName();
				$result   = self::$cloud_storage->getObject(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
					$local_package->getFileName()
				);

				if (
					$result &&
					is_file( $filename ) &&
					is_readable( $filename )
				) {
					$local_meta = Parser::parse_package( $filename, true );
				}
			} catch ( PhpS3Exception $e ) {
				php_log(
					array(
						'error'    => $e->getMessage(),
						'file'     => $e->getFile(),
						'line'     => $e->getLine(),
						'caller'   => $e->getTrace()[1],
						'filename' => $local_package->getFileName(),
					)
				);
			}
		}

		if ( is_file( $local_package->getFileName() ) ) {
			wp_delete_file( $local_package->getFileName() );
		}

		return $local_meta;
	}

	public function upserv_saved_remote_package_to_local( $local_ready, $type, $slug ) {
		$config            = self::get_config();
		$package_directory = Data_Manager::get_data_dir( 'packages' );
		$filename          = trailingslashit( $package_directory ) . $slug . '.zip';

		try {

			if ( $local_ready ) {
				$args = array(
					self::$cloud_storage::inputFile( $filename ),
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
					PhpS3::ACL_PRIVATE,
					array(
						'updatepulse-digests-sha1'   => hash_file( 'sha1', $filename ),
						'updatepulse-digests-sha256' => hash_file( 'sha256', $filename ),
						'updatepulse-digests-sha512' => hash_file( 'sha512', $filename ),
						'updatepulse-digests-crc32'  => hash_file( 'crc32b', $filename ),
						'updatepulse-digests-crc32c' => hash_file( 'crc32c', $filename ),
					),
				);

				self::$cloud_storage->putObject( ...$args );
			}
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'    => $e->getMessage(),
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
					'caller'   => $e->getTrace()[1],
					'filename' => $filename,
				)
			);
		}

		if ( is_file( $filename ) ) {
			wp_delete_file( $filename );
		}
	}

	public function upserv_did_manual_upload_package( $result, $type, $slug ) {

		if ( ! $result ) {
			return;
		}

		$config            = self::get_config();
		$package_directory = Data_Manager::get_data_dir( 'packages' );
		$filename          = trailingslashit( $package_directory ) . $slug . '.zip';

		try {
			self::$cloud_storage->putObjectFile(
				$filename,
				$config['storage_unit'],
				self::$virtual_dir . '/' . $slug . '.zip'
			);
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'    => $e->getMessage(),
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
					'caller'   => $e->getTrace()[1],
					'filename' => $filename,
				)
			);
		}

		if ( is_file( $filename ) ) {
			wp_delete_file( $filename );
		}
	}

	public function upserv_save_remote_to_local( $save, $slug, $filename, $check_remote ) {
		$config = self::get_config();

		try {

			if ( $check_remote ) {
				$info = wp_cache_get( $slug . '-getObjectInfo', 'updatepulse-server' );

				if ( false === $info ) {
					$info = self::$cloud_storage->getObjectInfo(
						$config['storage_unit'],
						self::$virtual_dir . '/' . $slug . '.zip',
					);

					wp_cache_set( $slug . '-getObjectInfo', $info, 'updatepulse-server' );
				}

				$save = false === $info;
			}
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'    => $e->getMessage(),
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
					'caller'   => $e->getTrace()[1],
					'filename' => $filename,
				)
			);
		}

		return $save;
	}

	public function upserv_before_packages_download( $archive_name, $archive_path, $package_slugs ) {

		if ( 1 === count( $package_slugs ) ) {
			$config = self::get_config();

			try {
				self::$cloud_storage->getObject(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $package_slugs[0] . '.zip',
					$archive_path
				);
			} catch ( PhpS3Exception $e ) {
				php_log(
					array(
						'error'    => $e->getMessage(),
						'file'     => $e->getFile(),
						'line'     => $e->getLine(),
						'caller'   => $e->getTrace()[1],
						'filename' => $archive_path,
					)
				);
			}
		} elseif ( ! empty( $package_slugs ) ) {

			foreach ( $package_slugs as $slug ) {
				$package_directory = Data_Manager::get_data_dir( 'packages' );
				$filename          = trailingslashit( $package_directory ) . $slug . '.zip';

				if ( is_file( $filename ) ) {
					wp_delete_file( $filename );
				}
			}
		}
	}

	public function upserv_before_packages_download_repack( $archive_name, $archive_path, $package_slugs ) {

		if ( ! empty( $package_slugs ) ) {
			$config = self::get_config();

			foreach ( $package_slugs as $slug ) {
				$package_directory = Data_Manager::get_data_dir( 'packages' );
				$filename          = trailingslashit( $package_directory ) . $slug . '.zip';

				try {
					self::$cloud_storage->getObject(
						$config['storage_unit'],
						self::$virtual_dir . '/' . $slug . '.zip',
						$filename
					);
				} catch ( PhpS3Exception $e ) {
					php_log(
						array(
							'error'    => $e->getMessage(),
							'file'     => $e->getFile(),
							'line'     => $e->getLine(),
							'caller'   => $e->getTrace()[1],
							'filename' => $filename,
						)
					);
				}
			}
		}
	}

	public function upserv_after_packages_download( $archive_name, $archive_path ) {

		if ( is_file( $archive_path ) ) {
			wp_delete_file( $archive_path );
		}
	}

	public function upserv_package_api_request( $method, $payload ) {
		$config = self::get_config();

		if ( 'download' === $method ) {
			$package_id = isset( $payload['package_id'] ) ? $payload['package_id'] : null;
			$type       = isset( $payload['type'] ) ? $payload['type'] : null;
			$info       = wp_cache_get( $package_id . '-getObjectInfo', 'updatepulse-server' );

			if ( false === $info ) {
				$info = self::$cloud_storage->getObjectInfo(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $package_id . '.zip',
				);

				wp_cache_set( $package_id . '-getObjectInfo', $info, 'updatepulse-server' );
			}

			if ( ! $info ) {
				$api = Package_API::get_instance();

				if ( ! $api->add( $package_id, $type ) ) {
					wp_send_json( array( 'message' => __( 'Package not found', 'updatepulse-server' ) ), 404 );
				}
			}

			$nonce = filter_input( INPUT_GET, 'token', FILTER_UNSAFE_RAW );

			if ( ! $nonce ) {
				$nonce = filter_input( INPUT_GET, 'nonce', FILTER_UNSAFE_RAW );
			}

			$url                  = self::$cloud_storage->getAuthenticatedUrlV4(
				$config['storage_unit'],
				self::$virtual_dir . '/' . $package_id . '.zip',
				abs( intval( upserv_get_nonce_expiry( $nonce ) ) ) - time(),
			);
			$this->doing_redirect = wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect

			do_action( 'upserv_did_download_package', $package_id );

			exit;
		}
	}

	public function upserv_find_package_no_cache( $slug, $filename, $cache ) {

		if ( is_file( $filename ) ) {
			return;
		}

		$config = self::get_config();

		try {
			$info = wp_cache_get( $slug . '-getObjectInfo', 'updatepulse-server' );

			if ( false === $info ) {
				$info = self::$cloud_storage->getObjectInfo(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
				);

				wp_cache_set( $slug . '-getObjectInfo', $info, 'updatepulse-server' );
			}

			$cache_key = self::build_cache_key( $slug, $filename );

			if ( $cache_key && ! $cache->get( $cache_key ) ) {
				$result = self::$cloud_storage->getObject(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
					$filename
				);

				if ( $result ) {
					$package     = Package::from_archive( $filename, $slug, $cache );
					$cache_value = $package->get_metadata();

					$cache->set( $cache_key, $cache_value, Zip_Metadata_Parser::$cache_time );
				}
			}
		} catch ( PhpS3Exception $e ) {
			php_log(
				array(
					'error'    => $e->getMessage(),
					'file'     => $e->getFile(),
					'line'     => $e->getLine(),
					'caller'   => $e->getTrace()[1],
					'filename' => $filename,
				)
			);
		}

		if ( is_file( $filename ) ) {
			wp_delete_file( $filename );
		}
	}

	public function upserv_zip_metadata_parser_cache_key( $cache_key, $slug, $filename ) {
		$cloud_cache_key = self::build_cache_key( $slug, $filename );

		return $cloud_cache_key ? $cloud_cache_key : $cache_key;
	}

	public function upserv_package_manager_get_package_info( $package_info, $slug ) {
		$cache             = new Cache( Data_Manager::get_data_dir( 'cache' ) );
		$config            = self::get_config();
		$package_directory = Data_Manager::get_data_dir( 'packages' );
		$filename          = $package_directory . $slug . '.zip';
		$cleanup           = ! is_file( $filename );

		try {
			$info = wp_cache_get( $slug . '-getObjectInfo', 'updatepulse-server' );

			if ( false === $info ) {
				$info = self::$cloud_storage->getObjectInfo(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
				);

				wp_cache_set( $slug . '-getObjectInfo', $info, 'updatepulse-server' );
			}

			$cache_key = self::build_cache_key( $slug, $filename );

			if ( ! $cache_key ) {
				return $package_info;
			}

			$package_info = $cache->get( $cache_key );

			if ( ! $package_info ) {
				$result = self::$cloud_storage->getObject(
					$config['storage_unit'],
					self::$virtual_dir . '/' . $slug . '.zip',
					$filename
				);

				if ( $result ) {

					try {
						$package      = Package::from_archive( $filename, $slug, $cache );
						$package_info = $package->get_metadata();

						$cache->set(
							$cache_key,
							$package_info,
							Zip_Metadata_Parser::$cache_time // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
						);
					} catch ( Invalid_Package_Exception $e ) {
						php_log(
							array(
								'error'    => $e->getMessage(),
								'file'     => $e->getFile(),
								'line'     => $e->getLine(),
								'caller'   => $e->getTrace()[1],
								'filename' => $filename,
							)
						);

						$cleanup = true;
					}
				}
			}

			if ( ! $package_info ) {

				if ( $cleanup && is_file( $filename ) ) {
					wp_delete_file( $filename );
				}

				return $package_info;
			}

			$digest_keys = array(
				'crc32',
				'crc32c',
				'sha1',
				'sha256',
				'sha512',
			);

			if ( ! isset( $package_info['type'] ) ) {
				$package_info['type'] = 'unknown';
			}

			$package_info['file_name']          = $package_info['slug'] . '.zip';
			$package_info['file_path']          = 'cloudStorage://' . self::$virtual_dir . '/' . $slug . '.zip';
			$package_info['file_size']          = $info['size'];
			$package_info['file_last_modified'] = $info['time'];
			$package_info['etag']               = $info['hash'];
			$package_info['digests']            = array();

			foreach ( $digest_keys as $key ) {

				if ( isset( $info[ 'x-amz-meta-updatepulse-digests-' . $key ] ) ) {
					$package_info['digests'][ $key ] = $info[ 'x-amz-meta-updatepulse-digests-' . $key ];
				}
			}
		} catch ( Exception $e ) {

			if ( $e instanceof PhpS3Exception ) {
				php_log(
					array(
						'error'    => $e->getMessage(),
						'file'     => $e->getFile(),
						'line'     => $e->getLine(),
						'caller'   => $e->getTrace()[1],
						'filename' => $filename,
					)
				);
			} else {
				php_log( 'Corrupt archive ' . $filename . ' ; package will not be displayed or delivered' );

				$log  = 'Exception caught: ' . $e->getMessage() . "\n";
				$log .= 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";

				php_log( $log );
			}
		}

		if ( $cleanup && is_file( $filename ) ) {
			wp_delete_file( $filename );
		}

		return $package_info;
	}

	public function upserv_package_manager_get_batch_package_info( $packages, $search ) {
		$config   = self::get_config();
		$contents = wp_cache_get( 'upserv-getBucket', 'updatepulse-server' );
		$packages = is_array( $packages ) ? $packages : array();

		if ( false === $contents ) {

			try {
				$contents = self::$cloud_storage->getBucket( $config['storage_unit'], self::$virtual_dir . '/' );
				unset( $contents[ self::$virtual_dir . '/' ] );

				if ( ! empty( $contents ) ) {
					$package_manager = Package_Manager::get_instance();

					foreach ( $contents as $item ) {
						$slug = str_replace( array( self::$virtual_dir . '/', '.zip' ), array( '', '' ), $item['name'] );
						$info = $package_manager->get_package_info( $slug );

						if ( $info ) {
							$include = true;

							if ( $search ) {

								if (
									false === strpos( strtolower( $info['name'] ), strtolower( $search ) ) ||
									false === strpos( strtolower( $info['slug'] ) . '.zip', strtolower( $search ) )
								) {
									$include = false;
								}
							}

							$include = apply_filters( 'upserv_batch_package_info_include', $include, $info, $search );

							if ( $include ) {
								$packages[ $info['slug'] ] = $info;
							}
						}
					}
				}
			} catch ( PhpS3Exception $e ) {
				php_log(
					array(
						'error'  => $e->getMessage(),
						'file'   => $e->getFile(),
						'line'   => $e->getLine(),
						'caller' => $e->getTrace()[1],
					)
				);
			}

			wp_cache_set( 'upserv-getBucket', $contents, 'updatepulse-server' );
		}

		return $packages;
	}

	public function upserv_update_server_action_download( $request ) {
		$config = self::get_config();
		$url    = self::$cloud_storage->getAuthenticatedUrlV4(
			$config['storage_unit'],
			self::$virtual_dir . '/' . $request->slug . '.zip',
			self::DOWNLOAD_URL_LIFETIME,
		);

		$this->doing_redirect = wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
	}

	public function upserv_update_server_action_download_handled() {
		return $this->doing_redirect;
	}

	public function upserv_is_package_whitelisted( $whitelisted, $package_slug ) {
		$data = upserv_get_package_metadata( $package_slug, false );

		if ( isset( $data['whitelisted'] ) && isset( $data['whitelisted']['cloud'] ) ) {
			return (bool) $data['whitelisted']['cloud'][0];
		}
	}

	public function upserv_whitelist_package_data( $data, $slug ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$data['whitelisted']['cloud'] = array(
			true,
			time(),
		);

		return $data;
	}

	protected static function build_cache_key( $slug, $filename ) {
		$config    = self::get_config();
		$info      = wp_cache_get( $slug . '-getObjectInfo', 'updatepulse-server' );
		$cache_key = false;

		if ( false === $info ) {
			$info = self::$cloud_storage->getObjectInfo(
				$config['storage_unit'],
				self::$virtual_dir . '/' . $slug . '.zip',
			);

			wp_cache_set( $slug . '-getObjectInfo', $info, 'updatepulse-server' );
		}

		if ( $info ) {
			$cache_key = $slug . '-b64-'
						. md5( $filename . '|' . $info['size'] . '|' . $info['time'] );
		}

		return $cache_key;
	}

	protected function virtual_folder_exists( $name ) {
		$config = self::get_config();

		return self::$cloud_storage->getObjectInfo(
			$config['storage_unit'],
			trailingslashit( $name )
		);
	}

	protected function create_virtual_folder( $name, $storage_unit = null ) {

		if ( ! $storage_unit ) {
			$config       = self::get_config();
			$storage_unit = $config['storage_unit'];
		}

		return self::$cloud_storage->putObject(
			trailingslashit( $name ),
			$storage_unit,
			trailingslashit( $name )
		);
	}
}
