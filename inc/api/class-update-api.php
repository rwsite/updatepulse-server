<?php

namespace Anyape\UpdatePulse\Server\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Anyape\UpdatePulse\Server\Manager\Data_Manager;

class Update_API {

	protected static $doing_update_api_request = null;
	protected static $instance;
	protected static $config;

	protected $update_server;

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {

			if ( ! self::is_doing_api_request() ) {
				add_action( 'init', array( $this, 'add_endpoints' ), 10, 0 );
			}

			add_action( 'parse_request', array( $this, 'parse_request' ), -99, 0 );
			add_action( 'upserv_checked_remote_package_update', array( $this, 'upserv_checked_remote_package_update' ), 10, 3 );
			add_action( 'upserv_removed_package', array( $this, 'upserv_removed_package' ), 10, 3 );
			add_action( 'upserv_primed_package_from_remote', array( $this, 'upserv_primed_package_from_remote' ), 10, 2 );

			add_filter( 'query_vars', array( $this, 'query_vars' ), -99, 1 );
			add_filter( 'puc_request_info_pre_filter', array( $this, 'puc_request_info_pre_filter' ), 10, 4 );
			add_filter( 'puc_request_info_result', array( $this, 'puc_request_info_result' ), 10, 4 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	public function add_endpoints() {
		add_rewrite_rule(
			'^updatepulse-server-update-api/*$',
			'index.php?$matches[1]&__upserv_update_api=1&',
			'top'
		);
	}

	public function parse_request() {
		global $wp;

		if ( isset( $wp->query_vars['__upserv_update_api'] ) ) {
			$this->handle_api_request();
		}
	}

	public function query_vars( $query_vars ) {
		$query_vars = array_merge(
			$query_vars,
			array(
				'__upserv_update_api',
				'action',
				'token',
				'package_id',
				'update_type',
			)
		);

		return $query_vars;
	}

	public function upserv_checked_remote_package_update( $needs_update, $type, $slug ) {
		$this->schedule_check_remote_event( $slug );
	}

	public function upserv_primed_package_from_remote( $result, $slug ) {

		if ( $result ) {
			$this->schedule_check_remote_event( $slug );
		}
	}

	public function upserv_removed_package( $result, $type, $slug ) {

		if ( $result ) {
			as_unschedule_all_actions( 'upserv_check_remote_' . $slug );
		}
	}

	public function puc_request_info_pre_filter( $info, $api_obj, $ref, $update_checker ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$config          = self::get_config();
		$filter_packages = apply_filters(
			'upserv_repository_filter_packages',
			$config['repository_filter_packages'],
			$info
		);

		$this->init_server( $info['slug'] );

		if ( $this->update_server && $filter_packages ) {
			$info = $this->update_server->pre_filter_package_info( $info, $api_obj, $ref );
		}

		return $info;
	}

	public function puc_request_info_result( $info, $api_obj, $ref, $checker ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$config          = self::get_config();
		$filter_packages = apply_filters(
			'upserv_repository_filter_packages',
			$config['repository_filter_packages'],
			$info
		);

		$this->init_server( $info['slug'] );

		if ( $this->update_server && $filter_packages ) {
			$info = $this->update_server->filter_package_info( $info );
		}

		return $info;
	}

	// Misc. -------------------------------------------------------

	public static function is_doing_api_request() {

		if ( null === self::$doing_update_api_request ) {
			self::$doing_update_api_request = ( false !== strpos( $_SERVER['REQUEST_URI'], 'updatepulse-server-update-api' ) );
		}

		return self::$doing_update_api_request;
	}

	public static function get_config() {

		if ( ! self::$config ) {
			$repo_configs = upserv_get_option( 'remote_repositories', array() );
			$idx          = empty( $repo_configs ) ? false : array_key_first( $repo_configs );
			$repo_config  = ( $idx ) ? $repo_configs[ $idx ] : false;
			$config       = array(
				'use_remote_repositories' => upserv_get_option( 'use_remote_repositories', 0 ),
				'server_directory'        => Data_Manager::get_data_dir(),
				'url'                     => ( $idx ) ? $repo_config['url'] : '',
				'self_hosted'             => ( $idx ) ? $repo_config['self_hosted'] : 0,
				'branch'                  => ( $idx ) ? $repo_config['branch'] : '',
				'credentials'             => ( $idx ) ? $repo_config['credentials'] : '',
				'filter_packages'         => ( $idx ) ? $repo_config['filter_packages'] : 0,
				'check_frequency'         => ( $idx ) ? $repo_config['check_frequency'] : 'daily',
			);

			if ( ! empty( $repo_configs ) ) {
				$needs_updated = false;

				foreach ( $repo_configs as $index => $r_c ) {
					$is_valid_schedule = in_array(
						strtolower( $r_c['check_frequency'] ),
						array_keys( wp_get_schedules() ),
						true
					);

					if ( ! $is_valid_schedule ) {
						$needs_updated                             = true;
						$repo_configs[ $index ]['check_frequency'] = 'daily';
					}
				}

				if ( $needs_updated ) {
					upserv_update_option( 'remote_repositories', $repo_configs );
				}
			}

			if ( 2 === count( $config['credentials'] ) ) {
				$config['repository_credentials'] = array(
					'consumer_key'    => $config['credentials'][0],
					'consumer_secret' => $config['credentials'][1],
				);
			} else {
				$config['credentials'] = $config['credentials'][0];
			}

			self::$config = $config;
		}

		return apply_filters( 'upserv_update_api_config', self::$config );
	}

	public static function get_instance() {

		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function check_remote_update( $slug, $type ) {
		$this->init_server( $slug );
		$this->update_server->set_type( $type );

		return $this->update_server->check_remote_package_update( $slug );
	}

	public function download_remote_package( $slug, $type = null, $force = false ) {
		$result = false;

		if ( ! $type ) {
			$types = array( 'Plugin', 'Theme', 'Generic' );

			foreach ( $types as $type ) {
				$result = $this->download_remote_package( $slug, $type, $force );

				if ( $result ) {
					break;
				}
			}

			return $result;
		}

		$type = ucfirst( strtolower( $type ) );

		$this->init_server( $slug );
		$this->update_server->set_type( $type );

		if ( ! upserv_is_package_whitelisted( $slug ) ) {
			upserv_whitelist_package( $slug );

			$meta        = upserv_get_package_metadata( $slug );
			$meta['vcs'] = $this->update_server->get_repository_service_url();

			upserv_set_package_metadata( $slug, $meta );
		}

		if ( $force || $this->update_server->check_remote_package_update( $slug ) ) {
			$result = $this->update_server->save_remote_package_to_local( $slug, $force );
		}

		return $result;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected function schedule_check_remote_event( $slug ) {
		$config = self::get_config();

		if (
			apply_filters( 'upserv_use_recurring_schedule', true ) &&
			$config['use_remote_repositories'] &&
			$config['url']
		) {
			$hook   = 'upserv_check_remote_' . $slug;
			$params = array( $slug, null, false );

			if ( ! as_has_scheduled_action( $hook, $params ) ) {
				$frequency = apply_filters(
					'upserv_check_remote_frequency',
					$config['repository_check_frequency'],
					$slug
				);
				$timestamp = time();
				$schedules = wp_get_schedules();
				$result    = as_schedule_recurring_action(
					$timestamp,
					$schedules[ $frequency ]['interval'],
					$hook,
					$params
				);

				do_action( 'upserv_scheduled_check_remote_event', $result, $slug, $timestamp, $frequency, $hook, $params );
			}
		}
	}

	protected function handle_api_request() {
		global $wp;

		$vars   = $wp->query_vars;
		$slug   = isset( $vars['package_id'] ) ? trim( rawurldecode( $vars['package_id'] ) ) : null;
		$params = array(
			'action' => isset( $vars['action'] ) ? trim( $vars['action'] ) : null,
			'token'  => isset( $vars['token'] ) ? trim( $vars['token'] ) : null,
			'slug'   => $slug,
			'type'   => isset( $vars['update_type'] ) ? trim( $vars['update_type'] ) : null,
		);
		$params = apply_filters(
			'upserv_handle_update_request_params',
			array_merge(
				$_GET, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$params
			)
		);

		$this->init_server( $slug );
		do_action( 'upserv_before_handle_update_request', $params );
		$this->update_server->handle_request( $params );
	}

	protected function init_server( $slug ) {
		$config      = self::get_config();
		$meta        = upserv_get_package_metadata( $slug );
		$url         = isset( $meta['url'] ) ?
			$meta['url'] :
			$config['url'];
		$_class_name = apply_filters(
			'upserv_server_class_name',
			str_replace( 'API', 'Server\\Update', __NAMESPACE__ ) . '\\Update_Server',
			$slug,
			$config
		);

		if ( ! isset( $this->update_server ) || ! is_a( $this->update_server, $_class_name ) ) {
			$this->update_server = new $_class_name(
				$config['use_remote_repositories'],
				home_url( '/updatepulse-server-update-api/' ),
				$config['server_directory'],
				$url,
				$config['repository_branch'],
				$config['repository_credentials'],
				$config['self_hosted'],
			);
		}

		$this->update_server = apply_filters(
			'upserv_update_server',
			$this->update_server,
			$config,
			$slug
		);
	}
}
