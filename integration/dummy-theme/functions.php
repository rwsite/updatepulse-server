<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/* ================================================================================================ */
/*                                  WP Packages Update Server                                         */
/* ================================================================================================ */

/**
* Uncomment the section below to enable updates with WP Packages Update Server.
*
* WARNING - READ FIRST:
*
* Before deploying the plugin or theme, make sure to change the value of `server` in wppus.json
* with the URL of the server where WP Packages Update Server is installed.
*
* Also change $prefix_updater below - replace "prefix" in this variable's name with a unique prefix
*
* If the plugin or theme requires a license, change the header `Require License` to either `yes`, `true`, or `1`
* in the main plugin file or the `style.css` file.
*
* If the plugin or theme uses the license of another plugin or theme, add the header `Licensed With`
* with the slug of the plugin or theme that provides the license in the main plugin file or the `style.css` file.
*
* @see https://github.com/froger-me/wp-packages-update-server/tree/main/integration/dummy-theme/lib/wp-package-updater
**/

/** Enable updates**/
require_once plugin_dir_path( __FILE__ ) . 'lib/wp-package-updater/class-wp-package-updater.php';

$dummy_theme_updater = new WP_Package_Updater(
	wp_normalize_path( __FILE__ ),
	get_stylesheet_directory()
);


/* ================================================================================================ */

function dummy_theme_enqueue_styles() {
	$parent_style = 'twentyseventeen-style';

	wp_enqueue_style( $parent_style, get_template_directory_uri() . '/style.css', array(), filemtime( __FILE__ ) );
	wp_enqueue_style(
		'child-style',
		get_stylesheet_directory_uri() . '/style.css',
		array( $parent_style ),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'dummy_theme_enqueue_styles' );
