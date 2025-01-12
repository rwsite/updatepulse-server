<?php
/*
Plugin Name: UpdatePulse Server
Plugin URI: https://github.com/anyape/updatepulse-server/
Description: Run your own update server.
Version: 2.0
Author: Alexandre Froger
Author URI: https://froger.me/
Text Domain: updatepulse-server
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Anyape\UpdatePulse\Updater\V2_0\UpdatePulse_Updater;
use Anyape\UpdatePulse\Server\UPServ_Nonce;
use Anyape\UpdatePulse\Server\UPServ_License_API;
use Anyape\UpdatePulse\Server\UPServ_Webhook_API;
use Anyape\UpdatePulse\Server\UPServ_Update_API;
use Anyape\UpdatePulse\Server\UPServ_Package_API;
use Anyape\UpdatePulse\Server\UPServ_Cloud_Storage_Manager;
use Anyape\UpdatePulse\Server\UPServ_Data_Manager;
use Anyape\UpdatePulse\Server\UPServ_Remote_Sources_Manager;
use Anyape\UpdatePulse\Server\UPServ_Webhook_Manager;
use Anyape\UpdatePulse\Server\UPServ_Package_Manager;
use Anyape\UpdatePulse\Server\UPServ_License_Manager;
use Anyape\UpdatePulse\Server\UPServ_API_Manager;
use Anyape\UpdatePulse\Server\UPServ;

if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {
	global $wpdb, $upserv_mem_before, $upserv_scripts_before, $upserv_queries_before;

	$upserv_mem_before     = memory_get_peak_usage();
	$upserv_scripts_before = get_included_files();
	$upserv_queries_before = $wpdb->queries;
}

if ( ! defined( 'UPSERV_PLUGIN_PATH' ) ) {
	define( 'UPSERV_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'UPSERV_PLUGIN_FILE' ) ) {
	define( 'UPSERV_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'UPSERV_PLUGIN_URL' ) ) {
	define( 'UPSERV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'UPSERV_MB_TO_B' ) ) {
	define( 'UPSERV_MB_TO_B', 1000000 );
}

$require = array(
	UPSERV_PLUGIN_PATH . 'inc/class-upserv-nonce.php',
	UPSERV_PLUGIN_PATH . 'inc/class-upserv-data-manager.php',
	UPSERV_PLUGIN_PATH . 'inc/class-upserv-cloud-storage-manager.php',
	UPSERV_PLUGIN_PATH . 'inc/class-upserv-update-api.php',
	UPSERV_PLUGIN_PATH . 'inc/class-upserv-license-api.php',
	UPSERV_PLUGIN_PATH . 'inc/class-upserv-webhook-api.php',
);

$require   = apply_filters( 'upserv_mu_require', $require );
$require[] = UPSERV_PLUGIN_PATH . 'lib/action-scheduler/action-scheduler.php';

foreach ( $require as $file ) {

	if ( file_exists( $file ) ) {
		require_once $file;
	}
}

if ( class_exists( 'Anyape\UpdatePulse\Server\UPServ_Nonce' ) ) {
	UPServ_Nonce::register();
	UPServ_Nonce::init_auth(
		array_merge(
			json_decode( get_option( 'upserv_package_private_api_keys', '{}' ), true ),
			json_decode( get_option( 'upserv_license_private_api_keys', '{}' ), true ),
		)
	);
}

if ( ! UPServ_License_API::is_doing_api_request() ) {
	require_once UPSERV_PLUGIN_PATH . 'lib/wp-update-server/loader.php';
	require_once UPSERV_PLUGIN_PATH . 'lib/wp-update-server-extended/loader.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-update-server.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-package-api.php';
}

if (
	! UPServ_Update_API::is_doing_api_request() &&
	! UPServ_License_API::is_doing_api_request()
) {
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-remote-sources-manager.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-webhook-manager.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-package-manager.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-license-manager.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-api-manager.php';

	$act_deact = array(
		'UPServ',
		'UPServ_License_Manager',
		'UPServ_Nonce',
		'UPServ_Webhook_manager',
	);

	foreach ( $act_deact as $class ) {
		$class = __NAMESPACE__ . '\\' . $class;

		if ( method_exists( $class, 'activate' ) ) {
			register_activation_hook( UPSERV_PLUGIN_FILE, array( $class, 'activate' ) );
		}

		if ( method_exists( $class, 'deactivate' ) ) {
			register_deactivation_hook( UPSERV_PLUGIN_FILE, array( $class, 'deactivate' ) );
		}

		if ( method_exists( $class, 'uninstall' ) ) {
			register_uninstall_hook( UPSERV_PLUGIN_FILE, array( $class, 'uninstall ' ) );
		}
	}
}

if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
	require_once UPSERV_PLUGIN_PATH . 'functions.php';
	require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-cli.php';

	WP_CLI::add_command( 'updatepulse-server', __NAMESPACE__ . '\\UPServ_CLI' );
}

function upserv_run() {
	wp_cache_add_non_persistent_groups( 'updatepulse-server' );

	require_once UPSERV_PLUGIN_PATH . 'functions.php';

	$license_api_request  = upserv_is_doing_license_api_request();
	$priority_api_request = apply_filters( 'upserv_is_priority_api_request', $license_api_request );
	$is_api_request       = $priority_api_request;
	$objects              = apply_filters( 'upserv_objects', array() );

	if ( ! isset( $objects['license_api'] ) ) {
		$objects['license_api'] = new UPServ_License_API( true, false );
	}

	if ( ! isset( $objects['webhook_api'] ) ) {
		$objects['webhook_api'] = new UPServ_Webhook_API( true );
	}

	if ( ! $priority_api_request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';

		do_action( 'upserv_no_priority_api_includes' );

		$is_api_request = (
			upserv_is_doing_update_api_request() ||
			upserv_is_doing_webhook_api_request() ||
			upserv_is_doing_package_api_request()
		);

		if ( ! isset( $objects['update_api'] ) ) {
			$objects['update_api'] = new UPServ_Update_API( true );
		}

		if ( ! isset( $objects['package_api'] ) ) {
			$objects['package_api'] = new UPServ_Package_API( true );
		}

		if ( ! isset( $objects['cloud_storage_manager'] ) ) {
			$objects['cloud_storage_manager'] = new UPServ_Cloud_Storage_Manager( true );
		}
	}

	$is_api_request = apply_filters( 'upserv_is_api_request', $is_api_request );

	if ( ! $is_api_request ) {

		if ( ! class_exists( 'WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}

		do_action( 'upserv_no_api_includes' );

		if ( ! isset( $objects['data_manager'] ) ) {
			$objects['data_manager'] = new UPServ_Data_Manager( true );
		}

		if ( ! isset( $objects['remote_sources_manager'] ) ) {
			$objects['remote_sources_manager'] = new UPServ_Remote_Sources_Manager( true );
		}

		if ( ! isset( $objects['webhook_manager'] ) ) {
			$objects['webhook_manager'] = new UPServ_Webhook_Manager( true );
		}

		if ( ! isset( $objects['package_manager'] ) ) {
			require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-packages-table.php';

			$objects['package_manager'] = new UPServ_Package_Manager( true );
		}

		if ( ! isset( $objects['license_manager'] ) ) {
			require_once UPSERV_PLUGIN_PATH . 'inc/class-upserv-licenses-table.php';

			$objects['license_manager'] = new UPServ_License_Manager( true );
		}

		if ( ! isset( $objects['api_manager'] ) ) {
			$objects['api_manager'] = new UPServ_API_Manager( true );
		}

		if ( ! isset( $objects['plugin'] ) ) {
			$objects['plugin'] = new UPServ( true );
		}
	}

	do_action( 'upserv_ready', $objects );
}
add_action( 'plugins_loaded', 'upserv_run', -99, 0 );

if (
	! UPServ_Update_API::is_doing_api_request() &&
	! UPServ_License_API::is_doing_api_request() &&
	! UPServ_Webhook_API::is_doing_api_request() &&
	! UPServ_Package_API::is_doing_api_request()
) {
	require_once __DIR__ . '/lib/wp-update-migrate/class-wp-update-migrate.php';

	if ( ! wp_doing_ajax() && is_admin() && ! wp_doing_cron() ) {
		add_action(
			'plugins_loaded',
			function () {
				$upserv_update_migrate = WP_Update_Migrate::get_instance( UPSERV_PLUGIN_FILE, 'upserv' );

				if ( false === $upserv_update_migrate->get_result() ) {

					if ( false !== has_action( 'plugins_loaded', 'upserv_run' ) ) {
						remove_action( 'plugins_loaded', 'upserv_run', -99 );
					}
				}
			},
			PHP_INT_MIN
		);
	}

	require_once plugin_dir_path( __FILE__ ) . 'lib/updatepulse-updater/class-updatepulse-updater.php';

	$upserv_plugin_updater = new UpdatePulse_Updater(
		wp_normalize_path( __FILE__ ),
		wp_normalize_path( plugin_dir_path( __FILE__ ) )
	);
}

if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'SAVEQUERIES' ) && SAVEQUERIES ) {

	if ( UPServ_Update_API::is_doing_api_request() || UPServ_License_API::is_doing_api_request() ) {
		require_once UPSERV_PLUGIN_PATH . 'tests.php';
	}
}
