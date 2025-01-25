<?php

namespace Anyape\UpdatePulse\Server\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use WP_Error;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Exception;
use Anyape\UpdatePulse\Package_Parser\Parser as Package_Parser;
use Anyape\UpdatePulse\Server\Server\Update\Zip_Metadata_Parser;
use Anyape\UpdatePulse\Server\Server\Update\Cache;
use Anyape\UpdatePulse\Server\Server\Update\Package;
use Anyape\UpdatePulse\Server\Manager\Data_Manager;
use Anyape\UpdatePulse\Server\API\Package_API;
use Anyape\UpdatePulse\Server\Table\Packages_Table;

class Package_Manager {

	const DEFAULT_LOGS_MAX_SIZE    = 10;
	const DEFAULT_CACHE_MAX_SIZE   = 100;
	const DEFAULT_ARCHIVE_MAX_SIZE = 20;

	public static $filesystem_clean_types = array(
		'cache',
		'logs',
	);

	protected static $instance;

	protected $packages_table;
	protected $rows = array();

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {
			add_action( 'admin_init', array( $this, 'admin_init' ), 10, 0 );
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 10, 0 );
			add_action( 'wp_ajax_upserv_force_clean', array( $this, 'force_clean' ), 10, 0 );
			add_action( 'wp_ajax_upserv_register_package_from_vcs', array( $this, 'register_package_from_vcs' ), 10, 0 );
			add_action( 'wp_ajax_upserv_manual_package_upload', array( $this, 'manual_package_upload' ), 10, 0 );
			add_action( 'load-toplevel_page_upserv-page', array( $this, 'add_page_options' ), 10, 0 );
			add_action( 'upserv_package_manager_pre_delete_package', array( $this, 'upserv_package_manager_pre_delete_package' ), 10, 1 );
			add_action( 'upserv_package_manager_deleted_package', array( $this, 'upserv_package_manager_deleted_package' ), 20, 1 );
			add_action( 'upserv_download_remote_package_aborted', array( $this, 'upserv_download_remote_package_aborted' ), 10, 3 );

			add_filter( 'upserv_admin_scripts', array( $this, 'upserv_admin_scripts' ), 10, 1 );
			add_filter( 'upserv_admin_styles', array( $this, 'upserv_admin_styles' ), 10, 1 );
			add_filter( 'upserv_admin_tab_links', array( $this, 'upserv_admin_tab_links' ), 10, 1 );
			add_filter( 'upserv_admin_tab_states', array( $this, 'upserv_admin_tab_states' ), 10, 2 );
			add_filter( 'set-screen-option', array( $this, 'set_page_options' ), 10, 3 );
			add_filter( 'upserv_batch_package_info_include', array( $this, 'batch_package_info_include' ), 10, 3 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	public function admin_init() {

		if ( is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			$this->packages_table = new Packages_Table( $this );

			if (
				(
					isset( $_REQUEST['_wpnonce'] ) &&
					wp_verify_nonce( $_REQUEST['_wpnonce'], $this->packages_table->nonce_action )
				) ||
				(
					isset( $_REQUEST['linknonce'] ) &&
					wp_verify_nonce( $_REQUEST['linknonce'], 'linknonce' )
				)
			) {
				$page                = isset( $_REQUEST['page'] ) ? $_REQUEST['page'] : false;
				$packages            = isset( $_REQUEST['packages'] ) ? $_REQUEST['packages'] : false;
				$delete_all_packages = isset( $_REQUEST['upserv_delete_all_packages'] ) ? true : false;
				$action              = false;

				if ( isset( $_REQUEST['action'] ) && -1 != $_REQUEST['action'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
					$action = $_REQUEST['action'];
				} elseif ( isset( $_REQUEST['action2'] ) && -1 != $_REQUEST['action2'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
					$action = $_REQUEST['action2'];
				}

				if ( 'upserv-page' === $page ) {

					if ( $packages && 'download' === $action ) {
						$error = $this->download_packages_bulk( $packages );

						if ( $error ) {
							$this->packages_table->bulk_action_error = $error;
						}
					} elseif ( $packages && 'delete' === $action ) {
						$this->delete_packages_bulk( $packages );
					} elseif ( $delete_all_packages ) {
						$this->delete_packages_bulk();
					} else {
						do_action( 'upserv_udpdate_manager_request_action', $action, $packages );
					}
				}
			}
		}
	}

	public function admin_menu() {
		$page_title = __( 'UpdatePulse Server', 'updatepulse-server' );
		$capability = 'manage_options';
		$function   = array( $this, 'plugin_page' );
		$menu_title = __( 'Packages Overview', 'updatepulse-server' );

		add_submenu_page( 'upserv-page', $page_title, $menu_title, $capability, 'upserv-page', $function );
	}

	public function add_page_options() {
		$option = 'per_page';
		$args   = array(
			'label'   => __( 'Packages per page', 'updatepulse-server' ),
			'default' => 10,
			'option'  => 'packages_per_page',
		);

		add_screen_option( $option, $args );
	}

	public function upserv_admin_scripts( $scripts ) {
		$l10n = array(
			'invalidFileFormat' => __( 'Error: invalid file format.', 'updatepulse-server' ),
			'invalidFileSize'   => __( 'Error: invalid file size.', 'updatepulse-server' ),
			'invalidFileName'   => __( 'Error: invalid file name.', 'updatepulse-server' ),
			'invalidFile'       => __( 'Error: invalid file', 'updatepulse-server' ),
			'deleteRecord'      => __( 'Are you sure you want to delete this record?', 'updatepulse-server' ),
		);

		if ( upserv_get_option( 'use_vcs' ) ) {
			$l10n['deletePackagesConfirm'] = array(
				__( 'You are about to delete all the packages from this server.', 'updatepulse-server' ),
				__( 'If a Webhook is configured, the packages will be re-downloaded from the VCS when it is triggered.', 'updatepulse-server' ),
				__( 'Other packages provided by the VCS will need to be registered again.', 'updatepulse-server' ),
				__( 'All packages manually uploaded will be permanently deleted.', 'updatepulse-server' ),
				"\n",
				__( 'Are you sure you want to do this?', 'updatepulse-server' ),
			);
		} else {
			$l10n['deletePackagesConfirm'] = array(
				__( 'You are about to delete all the packages from this server.', 'updatepulse-server' ),
				__( 'All packages will be permanently deleted.', 'updatepulse-server' ),
				"\n",
				__( 'Are you sure you want to do this?', 'updatepulse-server' ),
			);
		}

		$scripts['package'] = array(
			'path'   => UPSERV_PLUGIN_PATH . 'js/admin/package' . upserv_assets_suffix() . '.js',
			'uri'    => UPSERV_PLUGIN_URL . 'js/admin/package' . upserv_assets_suffix() . '.js',
			'deps'   => array( 'jquery' ),
			'params' => array(
				'debug'    => (bool) ( constant( 'WP_DEBUG' ) ),
				'ajax_url' => admin_url( 'admin-ajax.php' ),
			),
			'l10n'   => apply_filters( 'upserv_scripts_l10n', $l10n, 'package' ),
		);

		return $scripts;
	}

	public function upserv_admin_styles( $styles ) {
		$styles['package'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'css/admin/package' . upserv_assets_suffix() . '.css',
			'uri'  => UPSERV_PLUGIN_URL . 'css/admin/package' . upserv_assets_suffix() . '.css',
		);

		return $styles;
	}

	public function set_page_options( $status, $option, $value ) {
		return $value;
	}

	public function upserv_admin_tab_links( $links ) {
		$links['main'] = array(
			admin_url( 'admin.php?page=upserv-page' ),
			'<i class="fa-solid fa-cubes"></i>' . __( 'Packages Overview', 'updatepulse-server' ),
		);

		return $links;
	}

	public function upserv_admin_tab_states( $states, $page ) {
		$states['main'] = 'upserv-page' === $page;

		return $states;
	}

	public function force_clean() {
		$result = false;
		$type   = false;

		if ( isset( $_REQUEST['nonce'] ) && wp_verify_nonce( $_REQUEST['nonce'], 'upserv_plugin_options' ) ) {
			$type = filter_input( INPUT_POST, 'type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			if ( in_array( $type, self::$filesystem_clean_types, true ) ) {
				$result = Data_Manager::maybe_cleanup( $type, true );
			}
		}

		if ( $result && $type ) {
			wp_send_json_success( array( 'btnVal' => __( 'Force Clean', 'updatepulse-server' ) . ' (' . self::get_dir_size_mb( $type ) . ')' ) );
		} elseif ( in_array( $type, self::$filesystem_clean_types, true ) ) {
			$error = new WP_Error(
				__METHOD__,
				__( 'Error - check the directory is writable', 'updatepulse-server' )
			);

			wp_send_json_error( $error );
		}
	}

	public function upserv_download_remote_package_aborted( $safe_slug, $type, $info ) {
		wp_cache_set( 'upserv_download_remote_package_aborted', $info, 'updatepulse-server' );
	}

	public function register_package_from_vcs() {
		$result = false;
		$error  = false;
		$slug   = 'N/A';

		if ( isset( $_REQUEST['nonce'] ) && wp_verify_nonce( $_REQUEST['nonce'], 'upserv_plugin_options' ) ) {
			$slug    = filter_input( INPUT_POST, 'slug', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
			$vcs_key = filter_input( INPUT_POST, 'vcs_key', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			if ( $slug && $vcs_key ) {
				$vcs_config      = upserv_get_option( 'vcs/' . $vcs_key, false );
				$meta            = upserv_get_package_metadata( $slug );
				$meta['vcs_key'] = hash( 'sha256', trailingslashit( $vcs_config['url'] ) . '|' . $vcs_config['branch'] );

				upserv_set_package_metadata( $slug, $meta );

				$result = upserv_download_remote_package( $slug, null );
			} else {
				$error = new WP_Error(
					__METHOD__,
					__( 'Error - could not get remote package. Missing data - please reload the page and try again.', 'updatepulse-server' )
				);
			}
		} else {
			$error = new WP_Error(
				__METHOD__,
				__( 'Error - could not get remote package. The page has expired - please reload the page and try again.', 'updatepulse-server' )
			);
		}

		if ( wp_cache_get( 'upserv_download_remote_package_aborted', 'updatepulse-server' ) ) {
			$vcs_config = upserv_get_package_vcs_config( $slug );
			$error      = isset( $vcs_config['filter_packages'] ) && $vcs_config['filter_packages'] ?
				new WP_Error(
					__METHOD__,
					__( 'Error - could not get remote package. The package was filtered out because it is not linked to this server.', 'updatepulse-server' )
				) :
				new WP_Error(
					__METHOD__,
					__( 'Error - could not get remote package. The package was found and is valid, but the download was aborted. Please check the package is satisfying the requirements for this server.', 'updatepulse-server' )
				);

			wp_cache_delete( 'upserv_download_remote_package_aborted', 'updatepulse-server' );
		}

		do_action( 'upserv_registered_package_from_vcs', $result, $slug );

		if ( ! $error && $result ) {
			wp_send_json_success();
		} else {

			if ( ! $error ) {
				$error = new WP_Error(
					__METHOD__,
					__( 'Error - could not get remote package. Check if a repository with this slug exists and has a valid file structure.', 'updatepulse-server' )
				);
			}

			wp_send_json_error( $error );
		}
	}

	public function manual_package_upload() {
		$result      = false;
		$slug        = 'N/A';
		$parsed_info = false;
		$error_text  = __( 'Reload the page and try again.', 'updatepulse-server' );

		if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'upserv_plugin_options' ) ) {
			wp_send_json_error(
				new WP_Error(
					__METHOD__,
					__( 'Error - could not upload the package. The page has expired - please reload the page and try again.', 'updatepulse-server' )
				)
			);
		}

		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return;
		}

		$package_info = isset( $_FILES['package'] ) ? $_FILES['package'] : false;
		$valid        = (bool) ( $package_info );

		if ( ! $valid ) {
			$error_text = __( 'Something very wrong happened.', 'updatepulse-server' );
		}

		$valid_archive_formats = array(
			'multipart/x-zip',
			'application/zip',
			'application/zip-compressed',
			'application/x-zip-compressed',
		);

		if ( $valid && ! in_array( $package_info['type'], $valid_archive_formats, true ) ) {
			$valid      = false;
			$error_text = __( 'Make sure the uploaded file is a zip archive.', 'updatepulse-server' );
		}

		if ( $valid && 0 !== abs( intval( $package_info['error'] ) ) ) {
			$valid = false;

			switch ( $package_info['error'] ) {
				case UPLOAD_ERR_INI_SIZE:
					$error_text = ( 'The uploaded file exceeds the upload_max_filesize directive in php.ini.' );
					break;

				case UPLOAD_ERR_FORM_SIZE:
					$error_text = ( 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.' );
					break;

				case UPLOAD_ERR_PARTIAL:
					$error_text = ( 'The uploaded file was only partially uploaded.' );
					break;

				case UPLOAD_ERR_NO_FILE:
					$error_text = ( 'No file was uploaded.' );
					break;

				case UPLOAD_ERR_NO_TMP_DIR:
					$error_text = ( 'Missing a temporary folder.' );
					break;

				case UPLOAD_ERR_CANT_WRITE:
					$error_text = ( 'Failed to write file to disk.' );
					break;

				case UPLOAD_ERR_EXTENSION:
					$error_text = ( 'A PHP extension stopped the file upload. PHP does not provide a way to ascertain which extension caused the file upload to stop; examining the list of loaded extensions with phpinfo() may help.' );
					break;
			}
		}

		if ( $valid && 0 >= $package_info['size'] ) {
			$valid      = false;
			$error_text = __( 'Make sure the uploaded file is not empty.', 'updatepulse-server' );
		}

		if ( $valid ) {
			$parsed_info = Package_Parser::parse_package( $package_info['tmp_name'], true );
		}

		if ( $valid && ! $parsed_info ) {
			$valid      = false;
			$error_text = __( 'The uploaded package is not a valid Generic, Theme or Plugin package.', 'updatepulse-server' );
		}

		if ( $valid ) {
			$source      = $package_info['tmp_name'];
			$filename    = $package_info['name'];
			$slug        = str_replace( '.zip', '', $filename );
			$type        = ucfirst( $parsed_info['type'] );
			$destination = Data_Manager::get_data_dir( 'packages' ) . $filename;
			$result      = $wp_filesystem->move( $source, $destination, true );
		} else {
			$result = false;

			$wp_filesystem->delete( $package_info['tmp_name'] );
		}

		do_action( 'upserv_did_manual_upload_package', $result, $type, $slug );

		if ( $result ) {
			upserv_whitelist_package( $slug );

			$meta           = $this->get_package_metadata( $slug );
			$meta['origin'] = 'manual';

			$this->set_package_metadata( $slug, $meta );
			wp_send_json_success();
		} else {
			wp_send_json_error(
				new WP_Error(
					__METHOD__,
					__( 'Error - could not upload the package. ', 'updatepulse-server' ) . "\n\n" . $error_text
				)
			);
		}
	}

	public function upserv_package_manager_pre_delete_package( $package_slug ) {
		$info = upserv_get_package_info( $package_slug, false );

		wp_cache_set( 'upserv_package_manager_pre_delete_package_info', $info, 'updatepulse-server' );
	}

	public function upserv_package_manager_deleted_package( $package_slug ) {
		$package_info = wp_cache_get( 'upserv_package_manager_pre_delete_package_info', 'updatepulse-server' );

		if ( $package_info ) {
			$payload = array(
				'event'       => 'package_deleted',
				// translators: %1$s is the package type, %2$s is the package slug
				'description' => sprintf( esc_html__( 'The package of type `%1$s` and slug `%2$s` has been deleted on UpdatePulse Server' ), $package_info['type'], $package_slug ),
				'content'     => $package_info,
			);

			upserv_schedule_webhook( $payload, 'package' );
		}
	}

	public function batch_package_info_include( $_include, $info, $type ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return ! upserv_get_option( 'use_vcs' ) || upserv_is_package_whitelisted( $info['slug'] );
	}

	// Misc. -------------------------------------------------------

	public static function get_instance() {

		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function plugin_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sorry, you are not allowed to access this page.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		wp_cache_set( 'settings_notice', $this->plugin_options_handler(), 'upserv' );

		$use_vcs     = upserv_get_option( 'use_vcs', 0 );
		$vcs_configs = upserv_get_option( 'vcs', array() );
		$vcs_options = array();

		if ( ! empty( $vcs_configs ) ) {

			foreach ( $vcs_configs as $key => $vcs_c ) {
				$url                 = untrailingslashit( $vcs_c['url'] );
				$branch              = $vcs_c['branch'];
				$name                = $vcs_c['self_hosted'] ?
					__( 'Self-hosted', 'updatepulse-server' ) :
					upserv_get_vcs_name( $vcs_c['type'] );
				$identifier          = substr( $url, strrpos( $url, '/' ) + 1 );
				$name                = $name . ' - ' . $identifier . ' - ' . $branch;
				$vcs_options[ $key ] = $name;
			}
		}

		$package_rows = $this->get_batch_package_info();
		$options      = array(
			'use_vcs'          => $use_vcs,
			'archive_max_size' => upserv_get_option( 'limits/archive_max_size', self::DEFAULT_ARCHIVE_MAX_SIZE ),
			'cache_max_size'   => upserv_get_option( 'limits/cache_max_size', self::DEFAULT_CACHE_MAX_SIZE ),
			'logs_max_size'    => upserv_get_option( 'limits/logs_max_size', self::DEFAULT_LOGS_MAX_SIZE ),
		);

		$this->packages_table->set_rows( $package_rows );
		$this->packages_table->prepare_items();

		upserv_get_admin_template(
			'plugin-packages-page.php',
			array(
				'packages_table' => $this->packages_table,
				'cache_size'     => self::get_dir_size_mb( 'cache' ),
				'logs_size'      => self::get_dir_size_mb( 'logs' ),
				'package_rows'   => $package_rows,
				'packages_dir'   => Data_Manager::get_data_dir( 'packages' ),
				'vcs_options'    => $vcs_options,
				'options'        => $options,
			)
		);
	}

	public function delete_packages_bulk( $package_slugs = array() ) {
		$package_slugs         = is_array( $package_slugs ) ? $package_slugs : array( $package_slugs );
		$package_directory     = Data_Manager::get_data_dir( 'packages' );
		$package_paths         = glob( trailingslashit( $package_directory ) . '*.zip' );
		$package_names         = array();
		$deleted_package_slugs = array();
		$delete_all            = false;
		$package_paths         = apply_filters(
			'upserv_delete_packages_bulk_paths',
			$package_paths,
			$package_slugs
		);

		if ( ! empty( $package_paths ) ) {

			if ( empty( $package_slugs ) ) {
				$delete_all = true;
			}

			foreach ( $package_paths as $package_path ) {
				$package_name    = basename( $package_path );
				$package_names[] = $package_name;

				if ( $delete_all ) {
					$package_slugs[] = str_replace( '.zip', '', $package_name );
				}
			}
		} else {
			return;
		}

		$url           = home_url( '/updatepulse-server-update-api/' );
		$filter_args   = array(
			'url' => $url,
		);
		$_class_name   = apply_filters(
			'upserv_server_class_name',
			str_replace( 'Manager', 'Server\\Update', __NAMESPACE__ ) . '\\Update_Server',
			null,
			$filter_args
		);
		$args          = apply_filters(
			'upserv_server_constructor_args',
			array( $url, Data_Manager::get_data_dir(), '', '', '', '', false ),
			null,
			$filter_args
		);
		$update_server = new $_class_name( ...$args );

		do_action( 'upserv_package_manager_pre_delete_packages_bulk', $package_slugs );

		foreach ( $package_slugs as $slug ) {
			$package_name = $slug . '.zip';

			if ( in_array( $package_name, $package_names, true ) ) {
				do_action( 'upserv_package_manager_pre_delete_package', $slug );

				$result = $update_server->remove_package( $slug );

				do_action( 'upserv_package_manager_deleted_package', $slug, $result );

				if ( $result ) {
					upserv_unwhitelist_package( $slug );

					$deleted_package_slugs[] = $slug;

					unset( $this->rows[ $slug ] );
				}
			}
		}

		if ( ! empty( $deleted_package_slugs ) ) {
			do_action( 'upserv_package_manager_deleted_packages_bulk', $deleted_package_slugs );
		}

		return empty( $deleted_package_slugs ) ? false : $deleted_package_slugs;
	}

	public function download_packages_bulk( $package_slugs ) {
		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return null;
		}

		$package_directory = Data_Manager::get_data_dir( 'packages' );
		$total_size        = 0;
		$max_archive_size  = upserv_get_option( 'limits/archive_max_size', self::DEFAULT_ARCHIVE_MAX_SIZE );
		$package_slugs     = is_array( $package_slugs ) ? $package_slugs : array( $package_slugs );

		if ( 1 === count( $package_slugs ) ) {
			$archive_name = reset( $package_slugs );
			$archive_path = trailingslashit( $package_directory ) . $archive_name . '.zip';

			do_action( 'upserv_before_packages_download', $archive_name, $archive_path, $package_slugs );

			foreach ( $package_slugs as $package_slug ) {
				$total_size += filesize( trailingslashit( $package_directory ) . $package_slug . '.zip' );
			}

			if ( $max_archive_size < ( (float) ( $total_size / UPSERV_MB_TO_B ) ) ) {
				$this->packages_table->bulk_action_error = 'max_file_size_exceeded';

				return;
			}

			$this->trigger_packages_download( $archive_name, $archive_path );

			return;
		}

		$temp_directory = Data_Manager::get_data_dir( 'tmp' );
		$archive_name   = 'archive-' . time();
		$archive_path   = trailingslashit( $temp_directory ) . $archive_name . '.zip';

		do_action( 'upserv_before_packages_download_repack', $archive_name, $archive_path, $package_slugs );

		foreach ( $package_slugs as $package_slug ) {
			$total_size += filesize( trailingslashit( $package_directory ) . $package_slug . '.zip' );
		}

		if ( $max_archive_size < ( (float) ( $total_size / UPSERV_MB_TO_B ) ) ) {
			$this->packages_table->bulk_action_error = 'max_file_size_exceeded';

			return;
		}

		$zip = new ZipArchive();

		if ( ! $zip->open( $archive_path, ZIPARCHIVE::CREATE ) ) {
			return false;
		}

		foreach ( $package_slugs as $package_slug ) {
			$file = trailingslashit( $package_directory ) . $package_slug . '.zip';

			if ( is_file( $file ) ) {
				$zip->addFromString( $package_slug . '.zip', @file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$zip->close();

		do_action( 'upserv_before_packages_download', $archive_name, $archive_path, $package_slugs );
		$this->trigger_packages_download( $archive_name, $archive_path );
	}

	public function trigger_packages_download( $archive_name, $archive_path, $exit_or_die = true ) {

		if ( ! empty( $archive_path ) && is_file( $archive_path ) && ! empty( $archive_name ) ) {

			if ( ini_get( 'zlib.output_compression' ) ) {
				@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			}

			$md5 = md5_file( $archive_path );

			if ( $md5 ) {
				header( 'Content-MD5: ' . $md5 );
			}

			// Add Content-Digest or Repr-Digest header based on requested priority
			$digest_requested = isset( $_SERVER['HTTP_WANT_DIGEST'] ) && is_string( $_SERVER['HTTP_WANT_DIGEST'] ) ?
				$_SERVER['HTTP_WANT_DIGEST'] :
				( isset( $_SERVER['HTTP_WANT_REPR_DIGEST'] ) && is_string( $_SERVER['HTTP_WANT_REPR_DIGEST'] ) ?
					$_SERVER['HTTP_WANT_REPR_DIGEST'] :
					'sha-256=1'
				);
			$digest_field     = isset( $_SERVER['HTTP_WANT_DIGEST'] ) ?
				'Content-Digest' :
				( isset( $_SERVER['HTTP_WANT_REPR_DIGEST'] ) ? 'Repr-Digest' : 'Content-Digest' );

			if ( $digest_requested && $digest_field ) {
				$digests = array_map(
					function ( $digest ) {

						if ( strpos( $digest, '=' ) === false ) {
							return array( '', 0 ); // Return default value if '=' delimiter is missing
						}

						$parts = explode( '=', strtolower( trim( $digest ) ) );

						return array(
							$parts[0], // Algorithm
							isset( $parts[1] ) ? (int) $parts[1] : 0, // Priority
						);
					},
					explode( ',', $digest_requested )
				);

				$sha_digests = array_filter(
					$digests,
					function ( $digest ) {
						return ! empty( $digest[0] ) && str_starts_with( $digest[0], 'sha-' );
					}
				);

				// Find the digest with the highest priority
				$selected_digest = array_reduce(
					$sha_digests,
					function ( $carry, $item ) {
						return $carry[1] > $item[1] ? $carry : $item;
					},
					array( '', 0 )
				);

				if ( ! empty( $selected_digest[0] ) ) {
					$digest = str_replace( '-', '', $selected_digest[0] );

					if ( ! in_array( $digest, hash_algos(), true ) ) {
						$digest = '';
					}

					$hash = hash_file( $digest, $archive_path );

					if ( $hash ) {
						$safe_digest = htmlspecialchars( $digest, ENT_QUOTES, 'UTF-8' );
						$safe_hash   = htmlspecialchars( $hash, ENT_QUOTES, 'UTF-8' );

						header( "$digest_field: $safe_digest=$safe_hash" );
					}
				}
			}

			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $archive_name . '.zip"' );
			header( 'Content-Transfer-Encoding: binary' );
			header( 'Content-Length: ' . filesize( $archive_path ) );

			do_action( 'upserv_triggered_packages_download', $archive_name, $archive_path );

			echo @file_get_contents( $archive_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		do_action( 'upserv_after_packages_download', $archive_name, $archive_path );

		if ( $exit_or_die ) {
			exit;
		}
	}

	public function get_package_info( $slug ) {
		$package_info = wp_cache_get( 'package_info_' . $slug, 'updatepulse-server' );

		if ( false !== $package_info ) {
			return $package_info;
		}

		do_action( 'upserv_get_package_info', $package_info, $slug );

		if ( has_filter( 'upserv_package_manager_get_package_info' ) ) {
			$package_info = apply_filters( 'upserv_package_manager_get_package_info', $package_info, $slug );
		} else {
			$package_directory = Data_Manager::get_data_dir( 'packages' );

			if ( file_exists( $package_directory . $slug . '.zip' ) ) {
				$package = $this->get_package(
					$package_directory . $slug . '.zip',
					$slug
				);

				if ( $package ) {
					$package_info = $package->get_metadata();

					if ( ! isset( $package_info['type'] ) ) {
						$package_info['type'] = 'unknown';
					}

					$file_path                          = $package_directory . $slug . '.zip';
					$package_info['file_name']          = $slug . '.zip';
					$package_info['file_path']          = $file_path;
					$package_info['file_size']          = $package->get_file_size();
					$package_info['file_last_modified'] = $package->get_last_modified();
					$package_info['etag']               = hash_file( 'md5', $file_path );
					$package_info['digests']            = array(
						'sha1'   => hash_file( 'sha1', $file_path ),
						'sha256' => hash_file( 'sha256', $file_path ),
						'sha512' => hash_file( 'sha512', $file_path ),
						'crc32'  => hash_file( 'crc32', $file_path ),
						'crc32c' => hash_file( 'crc32c', $file_path ),
					);
				}
			}
		}

		wp_cache_set( 'package_info_' . $slug, $package_info, 'updatepulse-server' );

		$package_info = apply_filters( 'upserv_package_manager_package_info', $package_info, $slug );

		if ( is_array( $package_info ) && ! isset( $package_info['metadata'] ) ) {
			$package_info['metadata'] = $this->get_package_metadata( $slug );
		}

		return $package_info;
	}

	public function get_batch_package_info( $search = false ) {
		$packages = wp_cache_get( 'packages', 'updatepulse-server' );

		if ( false !== $packages ) {
			return empty( $packages ) ? array() : $packages;
		}

		if ( has_filter( 'upserv_package_manager_get_batch_package_info' ) ) {
			$packages = apply_filters( 'upserv_package_manager_get_batch_package_info', $packages, $search );
			wp_cache_set( 'packages', $packages, 'updatepulse-server' );

			return empty( $packages ) ? array() : $packages;
		}

		$package_directory = Data_Manager::get_data_dir( 'packages' );
		$packages          = array();

		if ( is_dir( $package_directory ) && ! Package_API::is_doing_api_request() ) {
			$search = isset( $_REQUEST['s'] ) ? // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_unslash( trim( $_REQUEST['s'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$search;
		}

		$package_paths = is_dir( $package_directory ) ?
			glob( trailingslashit( $package_directory ) . '*.zip' ) :
			array();

		if ( is_dir( $package_directory ) && ! empty( $package_paths ) ) {

			foreach ( $package_paths as $package_path ) {
				$package = $this->get_package(
					$package_path,
					str_replace(
						array( trailingslashit( $package_directory ), '.zip' ),
						array( '', '' ),
						$package_path
					)
				);

				if ( ! $package ) {
					continue;
				}

				$meta    = $package->get_metadata();
				$include = ! $search ? true : (
					$search &&
					(
						false === strpos( strtolower( $meta['name'] ), strtolower( $search ) ) ||
						false === strpos( strtolower( $meta['slug'] ) . '.zip', strtolower( $search ) )
					)
				);

				if ( ! $include ) {
					continue;
				}

				$slug                                    = $meta['slug'];
				$file_path                               = $package_directory . $slug . '.zip';
				$packages[ $slug ]                       = $meta;
				$packages[ $slug ]['metadata']           = $this->get_package_metadata( $slug );
				$packages[ $slug ]['file_name']          = $slug . '.zip';
				$packages[ $slug ]['file_path']          = $package_directory . $slug . '.zip';
				$packages[ $slug ]['file_size']          = $package->get_file_size();
				$packages[ $slug ]['file_last_modified'] = $package->get_last_modified();
				$packages[ $slug ]['etag']               = hash_file( 'md5', $file_path );
				$packages[ $slug ]['digests']            = array(
					'sha1'   => hash_file( 'sha1', $file_path ),
					'sha256' => hash_file( 'sha256', $file_path ),
					'sha512' => hash_file( 'sha512', $file_path ),
					'crc32'  => hash_file( 'crc32', $file_path ),
					'crc32c' => hash_file( 'crc32c', $file_path ),
				);

				wp_cache_set( 'package_info_' . $slug, $packages[ $slug ], 'updatepulse-server' );
			}
		}

		$packages = apply_filters( 'upserv_package_manager_batch_package_info', $packages, $search );

		wp_cache_set( 'packages', $packages, 'updatepulse-server' );

		if ( empty( $packages ) ) {
			$packages = array();
		}

		return $packages;
	}

	public function is_package_whitelisted( $package_slug ) {

		if ( has_filter( 'upserv_is_package_whitelisted' ) ) {
			return apply_filters( 'upserv_is_package_whitelisted', false, $package_slug );
		}

		$data = $this->get_package_metadata( $package_slug, false );

		if ( isset( $data['whitelisted'] ) && isset( $data['whitelisted']['local'] ) ) {
			return (bool) $data['whitelisted']['local'][0];
		}

		return false;
	}

	public function whitelist_package( $package_slug ) {
		$data = $this->get_package_metadata( $package_slug, false );

		if ( ! isset( $data['whitelisted'] ) ) {
			$data['whitelisted'] = array();
		}

		if ( has_filter( 'upserv_whitelist_package_data' ) ) {
			$data = apply_filters( 'upserv_whitelist_package_data', $data, $package_slug );
		} else {
			$data['whitelisted']['local'] = array( true, time() );
		}

		$result = $this->set_package_metadata( $package_slug, $data );

		do_action( 'upserv_whitelist_package', $package_slug, $data, $result );

		return $result;
	}

	public function unwhitelist_package( $package_slug ) {
		$data = $this->get_package_metadata( $package_slug, false );

		if ( ! isset( $data['whitelisted'] ) ) {
			$data['whitelisted'] = array();
		}

		if ( has_filter( 'upserv_unwhitelist_package_data' ) ) {
			$data = apply_filters( 'upserv_unwhitelist_package_data', $data, $package_slug );
		} else {
			$data['whitelisted']['local'] = array( false, time() );
		}

		$result = $this->set_package_metadata( $package_slug, $data );

		do_action( 'upserv_unwhitelist_package', $package_slug, $result );

		return $result;
	}

	public function get_package_metadata( $package_slug, $json_encode = false ) {
		$data = wp_cache_get( 'package_metadata_' . $package_slug, 'updatepulse-server' );

		if ( $data ) {
			return ! $json_encode ? json_decode( $data, true ) : $data;
		}

		$dir       = upserv_get_data_dir( 'metadata' );
		$filename  = sanitize_file_name( $package_slug . '.json' );
		$file_path = trailingslashit( $dir ) . $filename;
		$data      = '{}';

		if ( ! has_filter( 'upserv_get_package_metadata' ) && is_file( $file_path ) ) {
			$data = @file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		} elseif ( has_filter( 'upserv_get_package_metadata' ) ) {
			$data = apply_filters( 'upserv_get_package_metadata', $data, $package_slug, $json_encode );
		}

		wp_cache_set( 'package_metadata_' . $package_slug, $data, 'updatepulse-server' );

		if ( ! $json_encode ) {
			$data = json_decode( $data, true );
		}

		return $data;
	}

	public function set_package_metadata( $package_slug, $metadata ) {
		$dir       = upserv_get_data_dir( 'metadata' );
		$filename  = sanitize_file_name( $package_slug . '.json' );
		$file_path = trailingslashit( $dir ) . $filename;
		$result    = false;
		$data      = apply_filters( 'set_package_metadata_data', $metadata, $package_slug );

		wp_cache_delete( 'package_metadata_' . $package_slug, 'updatepulse-server' );

		if ( empty( $data ) ) {

			if ( ! has_filter( 'upserv_did_delete_package_metadata' ) && is_file( $file_path ) ) {
				WP_Filesystem();

				global $wp_filesystem;

				$result = (bool) $wp_filesystem->delete( $file_path );
			} else {
				$result = apply_filters( 'upserv_did_delete_package_metadata', false, $package_slug );
			}

			do_action( 'upserv_delete_package_metadata', $package_slug, $result );

			return $result;
		}

		$previous = $this->get_package_metadata( $package_slug );

		wp_cache_delete( 'package_metadata_' . $package_slug, 'updatepulse-server' );
		unset( $previous['previous'] );

		$data['timestamp'] = time();
		$data['previous']  = $previous;

		if ( ! has_filter( 'upserv_did_set_package_metadata' ) ) {
			$result = (bool) file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$file_path,
				wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ),
				FS_CHMOD_FILE
			);
		} else {
			$result = apply_filters( 'upserv_did_set_package_metadata', false, $package_slug, $data );
		}

		do_action( 'upserv_set_package_metadata', $package_slug, $data, $result );

		return $result;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected static function get_dir_size_mb( $type ) {
		$result = 'N/A';

		if ( ! Data_Manager::is_valid_data_dir( $type ) ) {
			return $result;
		}

		$directory  = Data_Manager::get_data_dir( $type );
		$total_size = 0;

		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory ) ) as $file ) {
			$total_size += $file->getSize();
		}

		$size = (float) ( $total_size / UPSERV_MB_TO_B );

		if ( $size < 0.01 ) {
			$result = '< 0.01 MB';
		} else {
			$result = number_format( $size, 2, '.', '' ) . 'MB';
		}

		return $result;
	}

	protected function plugin_options_handler() {
		$errors = array();
		$result = '';

		if (
			isset( $_REQUEST['upserv_plugin_options_handler_nonce'] ) &&
			! wp_verify_nonce( $_REQUEST['upserv_plugin_options_handler_nonce'], 'upserv_plugin_options' )
		) {
			$errors['general'] = __( 'There was an error validating the form. It may be outdated. Please reload the page.', 'updatepulse-server' );

			return $errors;
		} elseif ( ! isset( $_REQUEST['upserv_plugin_options_handler_nonce'] ) ) {
			return $result;
		}

		$result  = __( 'UpdatePulse Server options successfully updated', 'updatepulse-server' );
		$options = $this->get_submitted_options();
		$to_save = array();

		foreach ( $options as $option_name => $option_info ) {
			$condition = $option_info['value'];

			if ( isset( $option_info['condition'] ) && 'number' === $option_info['condition'] ) {
				$condition = is_numeric( $option_info['value'] );
			}

			$condition = apply_filters(
				'upserv_package_option_update',
				$condition,
				$option_name,
				$option_info,
				$options
			);

			if ( $condition && isset( $option_info['path'] ) ) {
				$to_save[ $option_info['path'] ] = $option_info['value'];
			} else {
				$errors[ $option_name ] = sprintf(
					// translators: %1$s is the option display name, %2$s is the condition for update
					__( 'Option %1$s was not updated. Reason: %2$s', 'updatepulse-server' ),
					$option_info['display_name'],
					$option_info['failure_display_message']
				);
			}
		}

		if ( ! empty( $to_save ) ) {
			$to_update = array();

			foreach ( $to_save as $path => $value ) {
				$to_update = upserv_set_option( $path, $value );
			}

			upserv_update_options( $to_update );
		}

		if ( ! empty( $errors ) ) {
			$result = $errors;
		}

		do_action( 'upserv_package_options_updated', $result );

		return $result;
	}

	protected function get_submitted_options() {

		return apply_filters(
			'upserv_submitted_package_config',
			array(
				'upserv_cache_max_size'   => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_cache_max_size', FILTER_VALIDATE_INT ),
					'display_name'            => __( 'Cache max size (in MB)', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid number', 'updatepulse-server' ),
					'condition'               => 'number',
					'path'                    => 'limits/cache_max_size',
				),
				'upserv_logs_max_size'    => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_logs_max_size', FILTER_VALIDATE_INT ),
					'display_name'            => __( 'Logs max size (in MB)', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid number', 'updatepulse-server' ),
					'condition'               => 'number',
					'path'                    => 'limits/logs_max_size',
				),
				'upserv_archive_max_size' => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_archive_max_size', FILTER_VALIDATE_INT ),
					'display_name'            => __( 'Archive max size (in MB)', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid number', 'updatepulse-server' ),
					'condition'               => 'number',
					'path'                    => 'limits/archive_max_size',
				),
			)
		);
	}

	protected function get_package( $filename, $slug ) {
		$package      = false;
		$cache        = new Cache( Data_Manager::get_data_dir( 'cache' ) );
		$cached_value = null;

		try {

			if ( is_file( $filename ) && is_readable( $filename ) ) {
				$cache_key    = Zip_Metadata_Parser::build_cache_key( $slug, $filename );
				$cached_value = $cache->get( $cache_key );
			}

			if ( null === $cached_value ) {
				do_action( 'upserv_find_package_no_cache', $slug, $filename, $cache );
			}

			$package = Package::from_archive( $filename, $slug, $cache );
		} catch ( Exception $e ) {
			php_log( 'Corrupt archive ' . $filename . '; package will not be displayed or delivered' );

			$log  = 'Exception caught: ' . $e->getMessage() . "\n";
			$log .= 'File: ' . $e->getFile() . ':' . $e->getLine() . "\n";

			php_log( $log );
		}

		return $package;
	}
}
