<?php

namespace Anyape\UpdatePulse\Server\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use Anyape\UpdatePulse\Server\Manager\Data_Manager;

class Update_API {

	protected static $doing_update_api_request = null;
	protected static $instance;

	protected $update_server;

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {
			add_action( 'init', array( $this, 'add_endpoints' ), 10, 0 );
			add_action( 'parse_request', array( $this, 'parse_request' ), -99, 0 );
			add_action( 'upserv_checked_remote_package_update', array( $this, 'upserv_checked_remote_package_update' ), 10, 3 );
			add_action( 'upserv_removed_package', array( $this, 'upserv_removed_package' ), 10, 3 );
			add_action( 'upserv_registered_package_from_vcs', array( $this, 'upserv_registered_package_from_vcs' ), 10, 2 );

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

	public function upserv_registered_package_from_vcs( $result, $slug ) {

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
		$vcs_config = upserv_get_package_vcs_config( $info['slug'] );

		if ( empty( $vcs_config ) ) {
			return $info;
		}

		$filter_packages = apply_filters(
			'upserv_vcs_filter_packages',
			$vcs_config['filter_packages'],
			$info
		);

		$this->init_server( $info['slug'] );

		if ( $this->update_server && $filter_packages ) {
			$info = $this->update_server->pre_filter_package_info( $info, $api_obj, $ref );
		}

		return $info;
	}

	public function puc_request_info_result( $info, $api_obj, $ref, $checker ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$vcs_config = upserv_get_package_vcs_config( $info['slug'] );

		if ( empty( $vcs_config ) ) {
			return $info;
		}

		$filter_packages = apply_filters(
			'upserv_vcs_filter_packages',
			$vcs_config['filter_packages'],
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
			$types = array( 'plugin', 'theme', 'generic' );

			foreach ( $types as $type ) {
				$result = $this->download_remote_package( $slug, $type, $force );

				if ( $result ) {
					break;
				}
			}

			return $result;
		}

		$this->init_server( $slug );
		$this->update_server->set_type( $type );

		if ( ! upserv_is_package_whitelisted( $slug ) ) {
			upserv_whitelist_package( $slug );
		}

		$meta            = upserv_get_package_metadata( $slug );
		$meta['vcs']     = trailingslashit( $this->update_server->get_vcs_url() );
		$meta['branch']  = $this->update_server->get_branch();
		$meta['type']    = $type;
		$meta['vcs_key'] = hash( 'sha256', trailingslashit( $meta['vcs'] ) . '|' . $meta['branch'] );
		$meta['origin']  = 'vcs';

		upserv_set_package_metadata( $slug, $meta );

		if ( $force || $this->update_server->check_remote_package_update( $slug ) ) {
			$result = $this->update_server->save_remote_package_to_local( $slug, $force );
		}

		return $result;
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected function schedule_check_remote_event( $slug ) {
		$vcs_config = upserv_get_package_vcs_config( $slug );

		if (
			! upserv_get_option( 'use_vcs', 0 ) ||
			empty( $vcs_config ) ||
			(
				isset( $vcs_config['use_webhooks'] ) &&
				$vcs_config['use_webhooks']
			)
		) {
			return;
		}

		$meta   = upserv_get_package_metadata( $slug );
		$type   = isset( $meta['type'] ) ? $meta['type'] : null;
		$hook   = 'upserv_check_remote_' . $slug;
		$params = array( $slug, $type, false );

		if ( as_has_scheduled_action( $hook, $params ) ) {
			return;
		}
		$frequency = apply_filters(
			'upserv_check_remote_frequency',
			$vcs_config['check_frequency'],
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

		do_action(
			'upserv_scheduled_check_remote_event',
			$result,
			$slug,
			$timestamp,
			$frequency,
			$hook,
			$params
		);
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

		if ( upserv_get_option( 'use_vcs' ) ) {
			$vcs_config  = upserv_get_package_vcs_config( $slug );
			$url         = isset( $vcs_config['url'] ) ? $vcs_config['url'] : false;
			$branch      = isset( $vcs_config['branch'] ) ? $vcs_config['branch'] : false;
			$credentials = isset( $vcs_config['credentials'] ) ? $vcs_config['credentials'] : '';
			$self_hosted = isset( $vcs_config['self_hosted'] ) ? $vcs_config['self_hosted'] : false;

			if ( ! $url || ! $branch ) {
				return;
			}
		}

		$filter_args = array(
			'url'         => isset( $url ) ? $url : null,
			'branch'      => isset( $branch ) ? $branch : null,
			'credentials' => isset( $credentials ) ? $credentials : null,
			'self_hosted' => isset( $self_hosted ) ? $self_hosted : null,
			'directory'   => Data_Manager::get_data_dir(),
			'vcs_config'  => isset( $vcs_config ) ? $vcs_config : null,
		);
		$_class_name = apply_filters(
			'upserv_server_class_name',
			str_replace( 'API', 'Server\\Update', __NAMESPACE__ ) . '\\Update_Server',
			$slug,
			$filter_args
		);
		$args        = apply_filters(
			'upserv_server_constructor_args',
			array(
				home_url( '/updatepulse-server-update-api/' ),
				Data_Manager::get_data_dir(),
				isset( $url ) ? $url : null,
				isset( $branch ) ? $branch : null,
				isset( $credentials ) ? $credentials : null,
				isset( $self_hosted ) ? $self_hosted : null,
			),
			$slug,
			$filter_args
		);

		if ( ! isset( $this->update_server ) || ! is_a( $this->update_server, $_class_name ) ) {
			$this->update_server = new $_class_name( ...$args );
		}
	}
}
