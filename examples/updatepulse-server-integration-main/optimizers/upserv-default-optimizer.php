<?php
/*
Plugin Name: UpdatePulse Server Default Optimizer
Description: Run as little as possible of the WordPress core with UpdatePulse Server actions and filters when handling API requests.
Version: 2.0
Author: Alexandre Froger
Author URI: https://froger.me/
*/

/**
 * Run as little as possible of the WordPress core with UpdatePulse Server actions and filters.
 * Effect:
 * - prevent inclusion of themes functions.php (parent and child)
 * - remove all core actions and filters that haven't been fired yet
 *
 * Place this file in a wp-content/mu-plugin folder and it will be loaded automatically.
 *
 * Use the following hooks in your own MU plugin for customization purposes:
 * - @see `upserv_mu_optimizer_doing_api_request` - filter; determine if the current request is an UpdatePulse Server API call
 * - @see `upserv_mu_optimizer_default_pre_apply` - action; fired before the default optimizer behavior is applied
 * - @see `upserv_mu_optimizer_remove_all_hooks` - filter; filter the hooks to remove all filters and actions from when UpdatePulse Server API calls are handled
 * - @see `upserv_mu_optimizer_default_applied` - action; fired after the default optimizer behavior is applied
 * - @see `upserv_mu_optimizer_info` - filter; filter the information to return when the optimizer is ready
 * - @see `upserv_mu_optimizer_ready` - action; fired when the optimizer is ready
 *
 * @see `updatepulse-server/updatepulse-server.php` and documentation for more MU plugin hooks.
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

function upserv_muplugins_loaded() {
	$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : false;
	$url  = '';

	if ( ! $host && isset( $_SERVER['SERVER_NAME'] ) ) {
		$host = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
	}

	if ( $host && isset( $_SERVER['REQUEST_URI'] ) ) {
		$request_uri = sanitize_url( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$url         = sanitize_url( 'https://' . $host . $request_uri );
	}

	$path      = str_replace( trailingslashit( home_url() ), '', $url );
	$frags     = explode( '/', $path );
	$doing_api = preg_match( '/^updatepulse-server-((.*?)-api|nonce|token)$/', $frags[0] );
	$hooks     = array();

	if ( apply_filters( 'upserv_mu_optimizer_doing_api_request', $doing_api ) ) {
		do_action( 'upserv_mu_optimizer_default_pre_apply' );

		$hooks = apply_filters(
			'upserv_mu_optimizer_remove_all_hooks',
			array(
				'registered_taxonomy',
				'wp_register_sidebar_widget',
				'registered_post_type',
				'auth_cookie_malformed',
				'auth_cookie_valid',
				'widgets_init',
				'wp_default_scripts',
				'option_siteurl',
				'option_home',
				'option_active_plugins',
				'query',
				'option_blog_charset',
				'plugins_loaded',
				'sanitize_comment_cookies',
				'template_directory',
				'stylesheet_directory',
				'determine_current_user',
				'set_current_user',
				'user_has_cap',
				'init',
				'option_category_base',
				'option_tag_base',
				'heartbeat_settings',
				'locale',
				'wp_loaded',
				'query_vars',
				'request',
				'parse_request',
				'shutdown',
			)
		);

		foreach ( $hooks as $hook ) {
			remove_all_filters( $hook );
		}

		add_filter( 'template_directory', fn() => __DIR__, PHP_INT_MAX - 100, 0 );
		add_filter( 'stylesheet_directory', fn() => __DIR__, PHP_INT_MAX - 100, 0 );
		add_filter( 'enable_loading_advanced_cache_dropin', fn() => false, PHP_INT_MAX - 100, 0 );
		do_action( 'upserv_mu_optimizer_default_applied' );
	}

	wp_cache_add_non_persistent_groups( 'updatepulse-server' );
	wp_cache_set( 'upserv_mu_doing_api', $doing_api, 'updatepulse-server' );
	do_action(
		'upserv_mu_optimizer_ready',
		$doing_api,
		$doing_api ?
			apply_filters( 'upserv_mu_optimizer_info', array( 'removed_hooks' => $hooks ) ) :
			false
	);
}

if ( ! defined( 'WP_CLI' ) ) {
	add_action( 'muplugins_loaded', 'upserv_muplugins_loaded', 0 );
}
