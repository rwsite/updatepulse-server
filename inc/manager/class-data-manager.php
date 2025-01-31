<?php

namespace Anyape\UpdatePulse\Server\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Data_Manager {

	public static $transient_data_dirs = array(
		'cache',
		'logs',
		'tmp',
	);

	public static $persistent_data_dirs = array(
		'packages',
		'metadata',
	);

	public static $transient_data_db = array(
		'update_from_remote_locks',
	);

	protected static $root_data_dirname = 'updatepulse-server';

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {
			add_action( 'action_scheduler_init', array( $this, 'action_scheduler_init' ), 10, 0 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	public static function activate() {
		set_transient( 'upserv_flush', 1, 60 );

		$result = self::maybe_setup_directories();

		if ( ! $result ) {
			$error_message = sprintf(
				// translators: %1$s is the path to the plugin's data directory
				__( 'Permission errors creating %1$s - could not setup the data directory. Please check the parent directory is writable.', 'updatepulse-server' ),
				'<code>' . self::get_data_dir() . '</code>'
			);

			die( $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$result = self::maybe_setup_mu_plugin();

		if ( $result ) {
			setcookie( 'upserv_activated_mu_success', '1', 60, '/', COOKIE_DOMAIN );
		} else {
			setcookie( 'upserv_activated_mu_failure', '1', 60, '/', COOKIE_DOMAIN );
		}

		self::register_schedules();
	}

	public static function deactivate() {
		self::clear_schedules();
	}

	public function action_scheduler_init() {
		self::register_cleanup_events();
		self::register_cleanup_schedules();
	}

	// Overrides ---------------------------------------------------

	// Misc. -------------------------------------------------------

	public static function clear_schedules() {
		self::clear_cleanup_schedules();
	}

	public static function register_schedules() {
		self::register_cleanup_events();
	}

	public static function maybe_setup_directories() {
		$root_dir = self::get_data_dir();
		$result   = true;

		if ( ! is_dir( $root_dir ) ) {
			$result = self::create_data_dir( 'updatepulse-server', false, true );
		}

		if ( $result ) {

			foreach ( array_merge( self::$transient_data_dirs, self::$persistent_data_dirs ) as $directory ) {

				if ( ! is_dir( $root_dir . $directory ) ) {
					$result = $result && self::create_data_dir( $directory );
				}
			}
		}

		return $result;
	}

	public static function maybe_setup_mu_plugin() {
		global $wp_filesystem;

		$result        = true;
		$mu_plugin_dir = trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) );
		$mu_plugin     = $mu_plugin_dir . 'upserv-endpoint-optimizer.php';

		if ( ! $wp_filesystem->is_dir( $mu_plugin_dir ) ) {
			$result = $wp_filesystem->mkdir( $mu_plugin_dir );
		}

		if ( $wp_filesystem->is_file( $mu_plugin ) ) {
			$result = $wp_filesystem->delete( $mu_plugin );
		}

		if ( $result && ! $wp_filesystem->is_file( $mu_plugin ) ) {
			$source_mu_plugin = wp_normalize_path(
				UPSERV_PLUGIN_PATH . 'optimisation/upserv-endpoint-optimizer.php'
			);
			$result           = $wp_filesystem->copy( $source_mu_plugin, $mu_plugin );
		}

		return $result;
	}

	public static function get_data_dir( $dir = 'root' ) {
		$data_dir = wp_cache_get( 'data_dir_' . $dir, 'updatepulse-server' );

		if ( false === $data_dir ) {
			WP_Filesystem();

			global $wp_filesystem;

			if ( ! $wp_filesystem ) {
				wp_die( 'File system not available.', __METHOD__ );
			}

			$data_dir = trailingslashit( $wp_filesystem->wp_content_dir() . self::$root_data_dirname );

			if ( 'root' !== $dir ) {

				if ( ! self::is_valid_data_dir( $dir ) ) {
					// translators: %1$s is the path to the plugin's data directory
					$error_message = sprintf( __( 'Directory <code>%1$s</code> is not a valid UpdatePulse Server data directory.', 'updatepulse-server' ), $dir );

					wp_die( $error_message, __METHOD__ ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}

				$data_dir .= $dir;
			}

			$data_dir = trailingslashit( $data_dir );

			wp_cache_set( 'data_dir_' . $dir, $data_dir, 'updatepulse-server' );
		}

		return $data_dir;
	}

	public static function is_valid_data_dir( $dir, $require_persistent = false ) {
		$is_valid = false;

		if ( ! $require_persistent ) {
			$is_valid = in_array( $dir, array_merge( self::$transient_data_dirs, self::$persistent_data_dirs ), true );
		} else {
			$is_valid = in_array( $dir, self::$persistent_data_dirs, true );
		}

		return $is_valid;
	}

	public static function maybe_cleanup( $type, $force = false ) {

		if ( in_array( $type, self::$transient_data_db, true ) ) {
			$method_name = 'maybe_cleanup_' . $type;

			if ( method_exists( get_called_class(), $method_name ) && ! $force ) {
				return static::$method_name();
			} else {
				return delete_option( 'upserv_' . $type );
			}
		}

		if ( self::is_valid_data_dir( $type ) ) {
			return self::maybe_cleanup_data_dir( $type, $force );
		}

		return false;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected static function maybe_cleanup_data_dir( $type, $force ) {
		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return false;
		}

		$directory              = self::get_data_dir( $type );
		$max_size_constant_name = __NAMESPACE__ . '\\Package_Manager::DEFAULT_'
			. strtoupper( $type )
			. '_MAX_SIZE';
		$default_max_size       = defined( $max_size_constant_name ) ? constant( $max_size_constant_name ) : 0;
		$cleanup                = false;
		$is_dir                 = is_dir( $directory );
		$total_size             = 0;

		if ( $default_max_size && $is_dir && false === $force ) {
			$max_size = upserv_get_option( 'limits/' . $type . '_max_size', $default_max_size );

			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory ) ) as $file ) {
				$size = $file->getSize();

				if ( false !== $size ) {
					$total_size += $size;
				}
			}

			if ( $total_size >= ( $max_size * UPSERV_MB_TO_B ) ) {
				$cleanup = true;
			}
		}

		if ( $is_dir && ( $cleanup || $force ) ) {
			$result = true;
			$result = $result && $wp_filesystem->delete( $directory, true );
			$result = $result && $wp_filesystem->mkdir( $directory );

			if ( self::is_valid_data_dir( $type ) ) {
				$result = $result && self::generate_restricted_htaccess( $directory );
			}

			do_action( 'upserv_did_cleanup', $result, $type, $total_size, $force );

			return $result;
		}

		return false;
	}

	protected static function maybe_cleanup_update_from_remote_locks() {
		$locks = get_option( 'upserv_update_from_remote_locks' );

		if ( is_array( $locks ) && ! empty( $locks ) ) {

			foreach ( $locks as $slug => $timestamp ) {

				if ( $timestamp <= time() ) {
					unset( $locks[ $slug ] );
				}
			}

			update_option( 'upserv_update_from_remote_locks', $locks );
		}
	}

	protected static function create_data_dir( $name, $include_htaccess = true, $is_root_dir = false ) {
		global $wp_filesystem;

		$root_dir = self::get_data_dir();
		$path     = ( $is_root_dir ) ? $root_dir : $root_dir . $name;
		$result   = $wp_filesystem->mkdir( $path );

		if ( $result && $include_htaccess ) {
			self::generate_restricted_htaccess( $path );
		}

		return $result;
	}

	protected static function generate_restricted_htaccess( $directory ) {
		WP_Filesystem();

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return false;
		}

		$contents = "Order deny,allow\nDeny from all";
		$htaccess = trailingslashit( $directory ) . '.htaccess';

		$wp_filesystem->touch( $htaccess );

		return $wp_filesystem->put_contents( $htaccess, $contents, 0644 );
	}

	protected static function clear_cleanup_schedules() {

		if ( upserv_is_doing_update_api_request() ) {
			return;
		}

		$cleanable_datatypes = array_merge( self::$transient_data_dirs, self::$transient_data_db );

		foreach ( $cleanable_datatypes as $type ) {
			$params = array( $type );

			if ( 'tmp' === $type ) {
				$params[] = true;
			}

			as_unschedule_all_actions( 'upserv_cleanup', $params );
			do_action( 'upserv_cleared_cleanup_schedule', $type, $params );
		}
	}

	protected static function register_cleanup_schedules() {

		if ( upserv_is_doing_update_api_request() ) {
			return false;
		}

		$cleanable_datatypes = array_merge( self::$transient_data_dirs, self::$transient_data_db );

		foreach ( $cleanable_datatypes as $type ) {
			$params = array( $type );

			if ( 'tmp' === $type ) {
				$params[] = true;
			}

			$hook = array( __NAMESPACE__ . '\\Data_Manager', 'maybe_cleanup' );

			add_action( 'upserv_cleanup', $hook, 10, 2 );
			do_action( 'upserv_registered_cleanup_schedule', $type, $params );
		}
	}

	protected static function register_cleanup_events() {
		$cleanable_datatypes = array_merge( self::$transient_data_dirs, self::$transient_data_db );

		foreach ( $cleanable_datatypes as $type ) {
			$params = array( $type );
			$hook   = 'upserv_cleanup';

			if ( 'tmp' === $type ) {
				$params[] = true;
			}

			if ( ! as_has_scheduled_action( $hook, $params ) ) {
				$frequency = apply_filters( 'upserv_schedule_cleanup_frequency', 'hourly', $type );
				$schedules = wp_get_schedules();
				$timestamp = time();
				$result    = as_schedule_recurring_action(
					$timestamp,
					$schedules[ $frequency ]['interval'],
					$hook,
					$params
				);

				do_action(
					'upserv_scheduled_cleanup_event',
					$result,
					$type,
					$timestamp,
					$frequency,
					$hook,
					$params
				);
			}
		}
	}
}
