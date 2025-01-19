<?php

namespace Anyape\UpdatePulse\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Exception;
use Anyape\UpdatePulse\Server\Manager\Data_Manager;
use Anyape\UpdatePulse\Server\Manager\Package_Manager;
use Anyape\UpdatePulse\Server\Manager\Remote_Sources_Manager;

class UPServ {

	protected static $instance;
	protected static $default_options;
	protected static $options;

	public function __construct( $init_hooks = false ) {
		self::$default_options = array(
			'use_remote_repositories' => 0,
			'use_licenses'            => 0,
			'use_cloud_storage'       => 0,
			'remote_repositories'     => (object) array(),
			'api'                     => array(
				'webhooks' => (object) array(),
				'licenses' => array(
					'private_api_keys'         => (object) array(),
					'private_api_ip_whitelist' => array(),
				),
				'packages' => array(
					'private_api_keys'         => (object) array(),
					'private_api_ip_whitelist' => array(),
				),
			),
			'cloud_storage'           => array(
				'access_key' => '',
				'secret_key' => '',
				'endpoint'   => '',
				'unit'       => '',
				'region'     => 'auto',
			),
			'limits'                  => array(
				'archive_max_size' => Package_Manager::DEFAULT_ARCHIVE_MAX_SIZE,
				'cache_max_size'   => Package_Manager::DEFAULT_CACHE_MAX_SIZE,
				'logs_max_size'    => Package_Manager::DEFAULT_LOGS_MAX_SIZE,
			),
		);
		self::$options         = $this->get_options();

		if ( $init_hooks ) {

			if ( ! upserv_is_doing_api_request() ) {
				$parts     = explode( DIRECTORY_SEPARATOR, untrailingslashit( UPSERV_PLUGIN_PATH ) );
				$plugin_id = end( $parts ) . '/updatepulse-server.php';

				add_action( 'init', array( $this, 'init' ), 99, 0 );
				add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 10, 1 );
				add_action( 'admin_menu', array( $this, 'admin_menu' ), 5, 0 );
				add_action( 'admin_menu', array( $this, 'admin_menu_help' ), 99, 0 );
				add_action( 'action_scheduler_failed_execution', array( $this, 'action_scheduler_failed_execution' ), 10, 3 );

				add_filter( 'upserv_admin_scripts', array( $this, 'upserv_admin_scripts' ), 10, 1 );
				add_filter( 'upserv_admin_styles', array( $this, 'upserv_admin_styles' ), 10, 1 );
				add_filter( 'plugin_action_links_' . $plugin_id, array( $this, 'add_action_links' ), 10, 1 );
				add_filter( 'upserv_admin_tab_links', array( $this, 'upserv_admin_tab_links' ), 99, 1 );
				add_filter( 'upserv_admin_tab_states', array( $this, 'upserv_admin_tab_states' ), 99, 2 );
				add_filter( 'action_scheduler_retention_period', array( $this, 'action_scheduler_retention_period' ), 10, 0 );
				add_filter( 'upserv_get_admin_template_args', array( $this, 'upserv_get_admin_template_args' ), 10, 2 );
			}

			add_action( 'init', array( $this, 'load_textdomain' ), 10, 0 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	public function action_scheduler_failed_execution( $action_id, Exception $exception, $context = '' ) {

		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		php_log(
			array(
				'action_id' => $action_id,
				'exception' => $exception,
				'context'   => $context,
			)
		);
	}

	// WordPress hooks ---------------------------------------------

	public static function activate() {

		if ( ! version_compare( phpversion(), '7.4', '>=' ) ) {
			$error_message  = __( 'PHP version 7.4 or higher is required. Current version: ', 'updatepulse-server' );
			$error_message .= phpversion();

			die( $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$error_message = __( 'The zip PHP extension is required by UpdatePulse Server. Please check your server configuration.', 'updatepulse-server' );

			die( $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		if ( ! get_option( 'upserv_plugin_version' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$plugin_data = get_plugin_data( UPSERV_PLUGIN_FILE );
			$version     = $plugin_data['Version'];

			update_option( 'upserv_plugin_version', $version );
		}

		if ( ! get_option( 'upserv_options' ) ) {
			$instance = self::get_instance();

			$instance->update_options( self::$default_options );
		}

		set_transient( 'upserv_flush', 1, 60 );

		$result = Data_Manager::maybe_setup_directories();

		if ( ! $result ) {
			$error_message = sprintf(
				// translators: %1$s is the path to the plugin's data directory
				__( 'Permission errors creating %1$s - could not setup the data directory. Please check the parent directory is writable.', 'updatepulse-server' ),
				'<code>' . Data_Manager::get_data_dir() . '</code>'
			);

			die( $error_message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$result = self::maybe_setup_mu_plugin();

		if ( $result ) {
			setcookie( 'upserv_activated_mu_success', '1', 60, '/', COOKIE_DOMAIN );
		} else {
			setcookie( 'upserv_activated_mu_failure', '1', 60, '/', COOKIE_DOMAIN );
		}

		Remote_Sources_Manager::register_schedules();
		Data_Manager::register_schedules();
	}

	public static function deactivate() {
		flush_rewrite_rules();

		Remote_Sources_Manager::clear_schedules();
		Data_Manager::clear_schedules();
	}

	public static function uninstall() {
		require_once UPSERV_PLUGIN_PATH . 'uninstall.php';
	}

	public function get_options() {
		$options = get_option( 'upserv_options' );
		$options = json_decode( $options, true );
		$options = $options ? $options : array();
		$options = array_merge( self::$default_options, $options );

		return apply_filters( 'upserv_get_options', $options );
	}

	public function update_options( $options ) {
		$options = array_merge( self::$options, $options );
		$options = apply_filters( 'upserv_update_options', $options );
		$result  = update_option(
			'upserv_options',
			wp_json_encode(
				$options,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
			),
			true
		);

		if ( $result ) {
			self::$options = $this->get_options();
		}

		return $result;
	}

	public function get_option( $path ) {
		$options = $this->get_options();
		$option  = access_nested_array( $options, $path );

		return apply_filters( 'upserv_get_option', $option, $path );
	}

	public function set_option( $path, $value ) {
		$options = self::$options;

		access_nested_array( $options, $path, $value, true );

		self::$options = $options;

		return self::$options;
	}

	public function update_option( $path, $value ) {
		$options = $this->get_options();

		access_nested_array( $options, $path, $value, true );

		return $this->update_options( $options );
	}

	public function init() {

		if ( get_transient( 'upserv_flush' ) ) {
			delete_transient( 'upserv_flush' );
			flush_rewrite_rules();
		}

		if ( filter_input( INPUT_COOKIE, 'upserv_activated_mu_failure', FILTER_UNSAFE_RAW ) ) {
			setcookie( 'upserv_activated_mu_failure', '', time() - 3600, '/', COOKIE_DOMAIN );
			add_action( 'admin_notices', array( $this, 'setup_mu_plugin_failure_notice' ), 10, 0 );
		}

		if ( filter_input( INPUT_COOKIE, 'upserv_activated_mu_success', FILTER_UNSAFE_RAW ) ) {
			setcookie( 'upserv_activated_mu_success', '', time() - 3600, '/', COOKIE_DOMAIN );
			add_action( 'admin_notices', array( $this, 'setup_mu_plugin_success_notice' ), 10, 0 );
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'updatepulse-server', false, '/languages' );
	}

	public function upserv_admin_styles( $styles ) {
		$styles['main'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'css/admin/main' . upserv_assets_suffix() . '.css',
			'uri'  => UPSERV_PLUGIN_URL . 'css/admin/main' . upserv_assets_suffix() . '.css',
		);

		return $styles;
	}

	public function upserv_admin_scripts( $scripts ) {
		$l10n = array(
			'invalidFileFormat' => array( __( 'Error: invalid file format.', 'updatepulse-server' ) ),
			'invalidFileSize'   => array( __( 'Error: invalid file size.', 'updatepulse-server' ) ),
			'invalidFileName'   => array( __( 'Error: invalid file name.', 'updatepulse-server' ) ),
			'invalidFile'       => array( __( 'Error: invalid file', 'updatepulse-server' ) ),
			'deleteRecord'      => array( __( 'Are you sure you want to delete this record?', 'updatepulse-server' ) ),
		);

		if ( get_option( 'upserv_use_remote_repository' ) ) {
			$l10n['deletePackagesConfirm'] = array(
				__( 'You are about to delete all the packages from this server.', 'updatepulse-server' ),
				__( 'Packages with a Remote Repository will be added again automatically whenever a client asks for updates.', 'updatepulse-server' ),
				__( 'All packages manually uploaded without counterpart in a Remote Repository will be permanently deleted.', 'updatepulse-server' ),
				"\n",
				__( 'Are you sure you want to do this?', 'updatepulse-server' ),
			);
		} else {
			$l10n['deletePackagesConfirm'] = array(
				__( 'You are about to delete all the packages from this server.', 'updatepulse-server' ),
				__( 'All packages will be permanently deleted.\n\nAre you sure you want to do this?', 'updatepulse-server' ),
				"\n",
				__( 'Are you sure you want to do this?', 'updatepulse-server' ),
			);
		}

		$l10n = apply_filters( 'upserv_page_upserv_scripts_l10n', $l10n );

		foreach ( $l10n as $key => $values ) {
			$l10n[ $key ] = implode( "\n", $values );
		}

		$scripts['main'] = array(
			'path'   => UPSERV_PLUGIN_PATH . 'js/admin/main' . upserv_assets_suffix() . '.js',
			'uri'    => UPSERV_PLUGIN_URL . 'js/admin/main' . upserv_assets_suffix() . '.js',
			'deps'   => array( 'jquery' ),
			'params' => array(
				'debug'    => (bool) ( constant( 'WP_DEBUG' ) ),
				'ajax_url' => admin_url( 'admin-ajax.php' ),
			),
			'l10n'   => array(
				'values' => $l10n,
			),
		);

		return $scripts;
	}

	public function admin_enqueue_scripts( $hook ) {

		if ( false !== strpos( $hook, 'page_upserv' ) ) {
			$this->enqueue_styles( array() );
			$this->enqueue_scripts( array() );
		}
	}

	public function admin_menu() {
		$page_title = __( 'UpdatePulse', 'updatepulse-server' );
		$menu_title = $page_title;
		$icon       = 'data:image/svg+xml;base64,PHN2ZyBpZD0iTGF5ZXJfMSIgZGF0YS1uYW1lPSJMYXllciAxIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNy44NSAxNS4zMSI+PGRlZnM+PHN0eWxlPi5jbHMtMXtmaWxsOiNhNGE0YTQ7fS5jbHMtMntmaWxsOiNhMGE1YWE7fTwvc3R5bGU+PC9kZWZzPjx0aXRsZT5VbnRpdGxlZC0xPC90aXRsZT48cGF0aCBjbGFzcz0iY2xzLTEiIGQ9Ik0xMCwxMy41NGMyLjIzLDAsNC40NiwwLDYuNjksMCwuNjksMCwxLS4xNSwxLS45MSwwLTIuMzUsMC00LjcxLDAtNy4wNiwwLS42NC0uMi0uODctLjg0LS44NS0xLjEzLDAtMi4yNiwwLTMuMzksMC0uNDQsMC0uNjgtLjExLS42OC0uNjJzLjIzLS42My42OC0uNjJjMS40MSwwLDIuODEsMCw0LjIyLDAsLjgyLDAsMS4yMS40MywxLjIsMS4yNywwLDIuOTMsMCw1Ljg3LDAsOC44LDAsMS0uMjksMS4yNC0xLjI4LDEuMjVxLTIuNywwLTUuNDEsMGMtLjU0LDAtLjg1LjA5LS44NS43NXMuMzUuNzMuODcuNzFjLjgyLDAsMS42NSwwLDIuNDgsMCwuNDgsMCwuNzQuMTguNzUuNjlzLS40LjUxLS43NS41MUg1LjJjLS4zNSwwLS43OC4xMS0uNzUtLjVzLjI4LS43MS43Ni0uN2MuODMsMCwxLjY1LDAsMi40OCwwLC41NCwwLC45NSwwLC45NC0uNzRzLS40OC0uNzEtMS0uNzFIMi41MWMtMS4yMiwwLTEuNS0uMjgtMS41LTEuNTFRMSw5LjE1LDEsNWMwLTEuMTQuMzQtMS40NiwxLjQ5LTEuNDdINi40NGMuNCwwLC43LDAsLjcxLjU3cy0uMjEuNjgtLjcuNjdjLTEuMTMsMC0yLjI2LDAtMy4zOSwwLS41NywwLS44My4xNy0uODIuNzhxMCwzLjYyLDAsNy4yNGMwLC42LjIxLjguOC43OUM1LjM2LDEzLjUyLDcuNjgsMTMuNTQsMTAsMTMuNTRaIiB0cmFuc2Zvcm09InRyYW5zbGF0ZSgtMSAtMi4xOSkiLz48cGF0aCBjbGFzcz0iY2xzLTIiIGQ9Ik0xMy4xLDkuMzhsLTIuNjIsMi41YS44MS44MSwwLDAsMS0xLjEyLDBMNi43NCw5LjM4YS43NC43NCwwLDAsMSwwLTEuMDguODIuODIsMCwwLDEsMS4xMywwTDkuMTMsOS41VjNhLjguOCwwLDAsMSwxLjU5LDBWOS41TDEyLDguM2EuODIuODIsMCwwLDEsMS4xMywwQS43NC43NCwwLDAsMSwxMy4xLDkuMzhaIiB0cmFuc2Zvcm09InRyYW5zbGF0ZSgtMSAtMi4xOSkiLz48L3N2Zz4=';

		add_menu_page( $page_title, $menu_title, 'manage_options', 'upserv-page', '', $icon );
	}

	public function admin_menu_help() {
		$function   = array( $this, 'help_page' );
		$page_title = __( 'UpdatePulse Server - Help', 'updatepulse-server' );
		$menu_title = __( 'Help', 'updatepulse-server' );
		$menu_slug  = 'upserv-page-help';

		add_submenu_page( 'upserv-page', $page_title, $menu_title, 'manage_options', $menu_slug, $function );
	}

	public function upserv_admin_tab_links( $links ) {
		$links['help'] = array(
			admin_url( 'admin.php?page=upserv-page-help' ),
			"<span class='dashicons dashicons-editor-help'></span> " . __( 'Help', 'updatepulse-server' ),
		);

		return $links;
	}

	public function upserv_admin_tab_states( $states, $page ) {
		$states['help'] = 'upserv-page-help' === $page;

		return $states;
	}

	public function add_action_links( $links ) {
		$link = array(
			'<a href="' . admin_url( 'admin.php?page=upserv-page' ) . '">' . __( 'Packages Overview', 'updatepulse-server' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=upserv-page-help' ) . '">' . __( 'Help', 'updatepulse-server' ) . '</a>',
		);

		return array_merge( $links, $link );
	}

	public function action_scheduler_retention_period() {
		return DAY_IN_SECONDS;
	}

	public function upserv_get_admin_template_args( $args, $template_name ) {

		if ( preg_match( '/^plugin-.*-page\.php$/', $template_name ) ) {
			$args['header'] = $this->display_settings_header( wp_cache_get( 'settings_notice', 'upserv' ) );
		}

		return $args;
	}

	// Misc. -------------------------------------------------------

	public static function get_instance() {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function locate_template( $template_name, $load = false, $required_once = true ) {
		$name     = str_replace( 'templates/', '', $template_name );
		$paths    = array(
			'plugins/updatepulse-server/templates/' . $name,
			'plugins/updatepulse-server/' . $name,
			'updatepulse-server/templates/' . $name,
			'updatepulse-server/' . $name,
		);
		$template = locate_template( apply_filters( 'upserv_locate_template_paths', $paths ) );

		if ( empty( $template ) ) {
			$template = UPSERV_PLUGIN_PATH . 'inc/templates/' . $template_name;
		}

		$template = apply_filters(
			'upserv_locate_template',
			$template,
			$template_name,
			str_replace( $template_name, '', $template )
		);

		if ( $load && '' !== $template ) {
			load_template( $template, $required_once );
		}

		return $template;
	}

	public static function locate_admin_template( $template_name, $load = false, $required_once = true ) {
		$template = apply_filters(
			'upserv_locate_admin_template',
			UPSERV_PLUGIN_PATH . 'inc/templates/admin/' . $template_name,
			$template_name,
			str_replace( $template_name, '', UPSERV_PLUGIN_PATH . 'inc/templates/admin/' )
		);

		if ( $load && '' !== $template ) {
			load_template( $template, $required_once );
		}

		return $template;
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

	public function setup_mu_plugin_failure_notice() {
		$class   = 'notice notice-error';
		$message = sprintf(
			// translators: %1$s is the path to the mu-plugins directory, %2$s is the path of the source MU Plugin
			__( 'Permission errors for <code>%1$s</code> - could not setup the endpoint optimizer MU Plugin. You may create the directory if necessary and manually copy <code>%2$s</code> in it (recommended).', 'updatepulse-server' ),
			trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ),
			wp_normalize_path( UPSERV_PLUGIN_PATH . 'optimisation/upserv-endpoint-optimizer.php' )
		);

		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function setup_mu_plugin_success_notice() {
		$class   = 'notice notice-info is-dismissible';
		$message = sprintf(
			// translators: %1$s is the path to the mu-plugin
			__( 'An endpoint optimizer MU Plugin has been confirmed to be installed in <code>%1$s</code>.', 'updatepulse-server' ),
			trailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) . 'upserv-endpoint-optimizer.php'
		);

		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function display_settings_header( $notice ) {
		echo '<h1>' . esc_html__( 'UpdatePulse Server', 'updatepulse-server' ) . '</h1>';

		if ( is_string( $notice ) && ! empty( $notice ) ) {
			echo '
				<div class="updated notice notice-success is-dismissible">
					<p>'
						. esc_html( $notice ) . '
					</p>
				</div>
			';
		} elseif ( is_array( $notice ) && ! empty( $notice ) ) {
			echo '
				<div class="error notice notice-error is-dismissible">
					<ul>';

			foreach ( $notice as $key => $message ) {
				echo '
				<li id="upserv_option_error_item_' . esc_attr( $key ) . '">'
					. esc_html( $message ) .
				'</li>';
			}

			echo '
					</ul>
				</div>';
		}

		$this->display_tabs();
	}

	public function help_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sorry, you are not allowed to access this page.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$options = array(
			'use_remote_repository' => get_option( 'upserv_use_remote_repository' ),
		);

		upserv_get_admin_template(
			'plugin-help-page.php',
			array(
				'packages_dir' => Data_Manager::get_data_dir( 'packages' ),
				'options'      => $options,
			)
		);
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected function display_tabs() {
		$states = $this->get_tab_states();
		$state  = array_filter( $states );

		if ( ! $state ) {
			return;
		}

		$state = array_keys( $state );
		$state = reset( $state );
		$links = apply_filters( 'upserv_admin_tab_links', array() );

		upserv_get_admin_template(
			'tabs.php',
			array(
				'states' => $states,
				'state'  => $state,
				'links'  => $links,
			)
		);
	}

	protected function get_tab_states() {
		$page   = filter_input( INPUT_GET, 'page', FILTER_UNSAFE_RAW );
		$states = array();

		if ( 0 === strpos( $page, 'upserv-page' ) ) {
			$states = apply_filters( 'upserv_admin_tab_states', $states, $page );
		}

		return $states;
	}

	protected function enqueue_styles( $styles ) {
		$filter = 'upserv_admin_styles';
		$styles = apply_filters( $filter, $styles );

		if ( ! empty( $styles ) ) {

			foreach ( $styles as $key => $values ) {

				if ( isset( $values['path'] ) && file_exists( $values['path'] ) ) {
					$version        = filemtime( $values['path'] );
					$values['deps'] = isset( $values['deps'] ) ? $values['deps'] : array();
					$suffix         = '-admin-style';

					wp_enqueue_style(
						'upserv-' . $key . $suffix,
						$values['uri'],
						$values['deps'],
						$version
					);

					if ( isset( $values['inline'] ) ) {
						wp_add_inline_style( 'upserv-' . $key . $suffix, $values['inline'] );
					}
				}
			}
		}

		return $styles;
	}

	protected function enqueue_scripts( $scripts ) {
		$filter  = 'upserv_admin_scripts';
		$scripts = apply_filters( $filter, $scripts );

		if ( ! empty( $scripts ) ) {

			foreach ( $scripts as $key => $values ) {

				if ( isset( $values['path'] ) && file_exists( $values['path'] ) ) {
					$version             = filemtime( $values['path'] );
					$values['deps']      = isset( $values['deps'] ) ? $values['deps'] : array();
					$values['in_footer'] = isset( $values['in_footer'] ) ? $values['in_footer'] : true;
					$suffix              = '-admin-script';

					wp_enqueue_script(
						'upserv-' . $key . $suffix,
						$values['uri'],
						$values['deps'],
						$version,
						$values['in_footer']
					);

					if ( isset( $values['params'] ) ) {
						$var_prefix              = 'UPServAdmin';
						$values['params_before'] = isset( $values['params_before'] ) ?
							$values['params_before'] :
							'before';

						wp_add_inline_script(
							'upserv-' . $key . $suffix,
							'var '
								. $var_prefix
								. ucfirst( str_replace( '-', '', ucwords( $key, '-' ) ) )
								. ' = '
								. wp_json_encode( $values['params'] ),
							$values['params_before']
						);
					}

					if ( isset( $values['l10n'] ) ) {
						$var_prefix               = 'UPServAdmin';
						$values['l10n']['var']    = isset( $values['l10n']['var'] ) ?
							$values['l10n']['var'] :
							$var_prefix
								. ucfirst( str_replace( '-', '', ucwords( $key, '-' ) ) )
								. '_l10n';
						$values['l10n']['values'] = isset( $values['l10n']['values'] ) ?
							$values['l10n']['values'] :
							array();

						wp_localize_script(
							'upserv-' . $key . $suffix,
							$values['l10n']['var'],
							$values['l10n']['values']
						);
					}
				}
			}
		}

		return $scripts;
	}
}
