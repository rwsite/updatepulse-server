<?php

namespace Anyape\UpdatePulse\Server\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use stdClass;

class Webhook_Manager {

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {
			add_action( 'upserv_template_remote_source_manager_option_before_recurring_check', array( $this, 'upserv_template_remote_source_manager_option_before_recurring_check' ), 10, 0 );

			add_filter( 'upserv_admin_scripts', array( $this, 'upserv_admin_scripts' ), 10, 1 );
			add_filter( 'upserv_submitted_remote_sources_config', array( $this, 'upserv_submitted_remote_sources_config' ), 10, 1 );
			add_filter( 'upserv_submitted_api_config', array( $this, 'upserv_submitted_api_config' ), 10, 1 );
			add_filter( 'upserv_remote_source_option_update', array( $this, 'upserv_remote_source_option_update' ), 10, 3 );
			add_filter( 'upserv_page_upserv_scripts_l10n', array( $this, 'upserv_page_upserv_scripts_l10n' ), 10, 1 );
			add_filter( 'upserv_use_recurring_schedule', array( $this, 'upserv_use_recurring_schedule' ), 10, 1 );
			add_filter( 'upserv_need_reschedule_remote_check_recurring_events', array( $this, 'upserv_need_reschedule_remote_check_recurring_events' ), 10, 1 );
		}
	}

	public static function activate() {

		if (
			! get_option( 'upserv_remote_repository_webhook_secret' ) ||
			'repository_webhook_secret' === get_option( 'upserv_remote_repository_webhook_secret' )
		) {
			update_option( 'upserv_remote_repository_webhook_secret', bin2hex( openssl_random_pseudo_bytes( 16 ) ) );
		}
	}

	public static function deactivate() {}

	public static function uninstall() {}

	public function upserv_admin_scripts( $scripts ) {
		$scripts['webhook'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'js/admin/webhook' . upserv_assets_suffix() . '.js',
			'uri'  => UPSERV_PLUGIN_URL . 'js/admin/webhook' . upserv_assets_suffix() . '.js',
			'deps' => array( 'jquery' ),
		);

		return $scripts;
	}

	public function upserv_page_upserv_scripts_l10n( $l10n ) {

		if (
			get_option( 'upserv_remote_repository_use_webhooks' ) &&
			get_option( 'upserv_use_remote_repository' )
		) {
			$l10n['deletePackagesConfirm'][1] = __( 'Packages with a Remote Repository will be added again automatically whenever a client asks for updates, or when its Webhook is called.', 'updatepulse-server' );
		}

		return $l10n;
	}

	public function upserv_use_recurring_schedule( $use_recurring_schedule ) {
		return $use_recurring_schedule && ! get_option( 'upserv_remote_repository_use_webhooks' );
	}

	public function upserv_submitted_remote_sources_config( $config ) {
		$config = array_merge(
			$config,
			array(
				'upserv_remote_repository_use_webhooks'   => array(
					'value'        => filter_input( INPUT_POST, 'upserv_remote_repository_use_webhooks', FILTER_VALIDATE_BOOLEAN ),
					'display_name' => __( 'Use Webhooks', 'updatepulse-server' ),
					'condition'    => 'boolean',
				),
				'upserv_remote_repository_check_delay'    => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_remote_repository_check_delay', FILTER_UNSAFE_RAW ),
					'display_name'            => __( 'Remote download delay', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid option', 'updatepulse-server' ),
					'condition'               => 'positive number',
				),
				'upserv_remote_repository_webhook_secret' => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_remote_repository_webhook_secret', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Remote repository Webhook Secret', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'condition'               => 'non-empty',
				),
			)
		);

		return $config;
	}

	public function upserv_remote_source_option_update( $condition, $option_name, $option_info ) {

		if ( 'upserv_remote_repository_use_webhooks' === $option_name ) {
			wp_cache_set(
				'upserv_remote_repository_use_webhooks',
				array(
					'new' => $option_info['value'],
					'old' => get_option( 'upserv_remote_repository_use_webhooks' ),
				),
				'updatepulse-server'
			);
		}

		if ( 'non-empty' === $option_info['condition'] ) {
			$condition = ! empty( $option_info['value'] );
		}

		if ( 'positive number' === $option_info['condition'] ) {
			$condition = is_numeric( $option_info['value'] ) && intval( $option_info['value'] ) >= 0;
		}

		return $condition;
	}

	public function upserv_need_reschedule_remote_check_recurring_events( $need_reschedule ) {
		$states = wp_cache_get( 'upserv_remote_repository_use_webhooks', 'updatepulse-server' );

		if ( is_array( $states ) && $states['old'] && ! $states['new'] ) {
			$need_reschedule = true;
		}

		return $need_reschedule;
	}

	public function upserv_submitted_api_config( $config ) {

		$config = array_merge(
			$config,
			array(
				'upserv_webhooks' => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_webhooks', FILTER_UNSAFE_RAW ),
					'display_name'            => __( 'Webhooks', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid payload', 'updatepulse-server' ),
					'condition'               => 'webhooks',
				),
			)
		);

		return $config;
	}

	public function upserv_api_option_update( $condition, $option_name, $option_info ) {

		if ( 'webhooks' === $option_info['condition'] ) {
			$inputs = json_decode( $option_info['value'], true );

			if ( empty( $option_info['value'] ) || json_last_error() ) {
				$option_info['value'] = '{}';
			} else {
				$filtered = array();

				foreach ( $inputs as $url => $values ) {
					$url             = filter_var( $url, FILTER_SANITIZE_URL );
					$events          = filter_var(
						isset( $values['events'] ) ? $values['events'] : array(),
						FILTER_SANITIZE_FULL_SPECIAL_CHARS,
						FILTER_REQUIRE_ARRAY
					);
					$secret          = filter_var(
						isset( $values['secret'] ) ? $values['secret'] : false,
						FILTER_SANITIZE_FULL_SPECIAL_CHARS
					);
					$license_api_key = filter_var(
						isset( $values['secret'] ) ? $values['secret'] : false,
						FILTER_SANITIZE_FULL_SPECIAL_CHARS
					);

					if ( ! $url || empty( $events ) || ! $secret ) {
						$filtered = new stdClass();

						break;
					}

					$filtered[ $url ] = array(
						'secret'        => $secret,
						'events'        => $events,
						'licenseAPIKey' => $license_api_key,
					);
				}

				$option_info['value'] = wp_json_encode(
					$filtered,
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
				);
			}
		}

		return $condition;
	}

	public function upserv_template_remote_source_manager_option_before_recurring_check() {
		$options = array(
			'use_webhooks'   => get_option( 'upserv_remote_repository_use_webhooks' ),
			'check_delay'    => get_option( 'upserv_remote_repository_check_delay', 0 ),
			'webhook_secret' => get_option( 'upserv_remote_repository_webhook_secret', 'repository_webhook_secret' ),
		);

		upserv_get_admin_template(
			'remote-webhook-options.php',
			array(
				'options' => $options,
			)
		);
	}
}
