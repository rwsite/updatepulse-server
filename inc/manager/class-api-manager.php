<?php

namespace Anyape\UpdatePulse\Server\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use stdClass;

class API_Manager {

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 20, 0 );

			add_filter( 'upserv_admin_scripts', array( $this, 'upserv_admin_scripts' ), 10, 1 );
			add_filter( 'upserv_admin_styles', array( $this, 'upserv_admin_styles' ), 10, 1 );
			add_filter( 'upserv_admin_tab_links', array( $this, 'upserv_admin_tab_links' ), 20, 1 );
			add_filter( 'upserv_admin_tab_states', array( $this, 'upserv_admin_tab_states' ), 20, 2 );
			add_filter( 'upserv_page_upserv_scripts_l10n', array( $this, 'upserv_page_upserv_scripts_l10n' ), 20, 2 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	public function upserv_admin_styles( $styles ) {
		$styles['api'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'css/admin/api' . upserv_assets_suffix() . '.css',
			'uri'  => UPSERV_PLUGIN_URL . 'css/admin/api' . upserv_assets_suffix() . '.css',
		);

		return $styles;
	}

	public function upserv_admin_scripts( $scripts ) {
		$scripts['api'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'js/admin/api' . upserv_assets_suffix() . '.js',
			'uri'  => UPSERV_PLUGIN_URL . 'js/admin/api' . upserv_assets_suffix() . '.js',
			'deps' => array( 'jquery' ),
		);

		return $scripts;
	}

	public function upserv_page_upserv_scripts_l10n( $l10n ) {
		$l10n['deleteApiKeyConfirm']           = array(
			__( 'You are about to delete an API key.', 'updatepulse-server' ),
			__( 'If you proceed, the remote systems using it will not be able to access the API anymore.', 'updatepulse-server' ),
			"\n",
			__( 'Are you sure you want to do this?', 'updatepulse-server' ),
		);
		$l10n['deleteApiWebhookConfirm']       = array(
			__( 'You are about to delete a Webhook.', 'updatepulse-server' ),
			__( 'If you proceed, the Payload URL will not receive the configured events anymore.', 'updatepulse-server' ),
			"\n",
			__( 'Are you sure you want to do this?', 'updatepulse-server' ),
		);
		$l10n['addWebhookNoLicenseApiConfirm'] = array(
			__( 'You are about to add a Webhook without License API Key ID.', 'updatepulse-server' ),
			__( 'If you proceed, the Payload URL will receive events for ALL licenses.', 'updatepulse-server' ),
			"\n",
			__( 'Are you sure you want to do this?', 'updatepulse-server' ),
		);
		$l10n['actionApiCountSingular']        = array(
			__( '1 action', 'updatepulse-server' ),
		);
		$l10n['actionApiCountSingularOther']   = array(
			__( '1 action (all records)', 'updatepulse-server' ),
		);
		$l10n['actionApiCountPlural']          = array(
			// translators: %d is the number of actions
			__( '%d actions', 'updatepulse-server' ),
		);
		$l10n['actionApiCountPluralOther']     = array(
			// translators: %d is the number of actions
			__( '%d actions (all records)', 'updatepulse-server' ),
		);
		$l10n['actionApiCountAll']             = array(
			__( 'All actions', 'updatepulse-server' ),
		);
		$l10n['actionApiCountAllOther']        = array(
			__( 'All actions (all records)', 'updatepulse-server' ),
		);
		$l10n['eventApiCountAll']              = array(
			__( 'All events', 'updatepulse-server' ),
		);
		$l10n['eventApiCountAllType']          = array(
			// translators: %s is the type of events
			__( 'All %s events', 'updatepulse-server' ),
		);
		$l10n['eventApiCountTypeSingular']     = array(
			// translators: %s is the type of event
			__( '1 %s event', 'updatepulse-server' ),
		);
		$l10n['eventApiCountTypePlural']       = array(
			// translators: %1$d is the number of events, %s is the type of events
			__( '%1$d %2$s events', 'updatepulse-server' ),
		);
		$l10n['eventApiTypePackage']           = array(
			_x( 'package', 'UpdatePulse Server webhook event type', 'updatepulse-server' ),
		);
		$l10n['eventApiTypeLicense']           = array(
			_x( 'license', 'UpdatePulse Server webhook event type', 'updatepulse-server' ),
		);
		$l10n['apiSumSep']                     = array(
			// translators: the separator between summaries ; example: All package events, 3 license events
			_x( ', ', 'UpdatePulse Server separator between API summaries', 'updatepulse-server' ),
		);

		return $l10n;
	}

	public function admin_menu() {
		$function   = array( $this, 'plugin_page' );
		$page_title = __( 'UpdatePulse Server - API & Webhooks', 'updatepulse-server' );
		$menu_title = __( 'API & Webhooks', 'updatepulse-server' );
		$menu_slug  = 'upserv-page-api';

		add_submenu_page( 'upserv-page', $page_title, $menu_title, 'manage_options', $menu_slug, $function );
	}

	public function upserv_admin_tab_links( $links ) {
		$links['api'] = array(
			admin_url( 'admin.php?page=upserv-page-api' ),
			"<span class='dashicons dashicons-rest-api'></span> " . __( 'API & Webhooks', 'updatepulse-server' ),
		);

		return $links;
	}

	public function upserv_admin_tab_states( $states, $page ) {
		$states['api'] = 'upserv-page-api' === $page;

		return $states;
	}

	// Misc. -------------------------------------------------------

	public function plugin_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sorry, you are not allowed to access this page.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		wp_cache_set( 'settings_notice', $this->plugin_options_handler(), 'upserv' );
		upserv_get_admin_template(
			'plugin-api-page.php',
			array(
				'license_api_actions' => apply_filters(
					'upserv_api_license_actions',
					array()
				),
				'package_api_actions' => apply_filters(
					'upserv_api_package_actions',
					array()
				),
				'webhook_events'      => apply_filters(
					'upserv_api_webhook_events',
					array(
						'package' => array(
							'label'  => __( 'Package events', 'updatepulse-server' ),
							'events' => array(),
						),
						'license' => array(
							'label'  => __( 'License events', 'updatepulse-server' ),
							'events' => array(),
						),
					)
				),
			)
		);
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected function plugin_options_handler() {
		$errors = array();
		$result = '';

		if (
			isset( $_REQUEST['upserv_plugin_options_handler_nonce'] ) &&
			wp_verify_nonce( $_REQUEST['upserv_plugin_options_handler_nonce'], 'upserv_plugin_options' )
		) {
			$result  = __( 'UpdatePulse Server options successfully updated', 'updatepulse-server' );
			$options = $this->get_submitted_options();

			foreach ( $options as $option_name => $option_info ) {
				$condition = $option_info['value'];

				if ( isset( $option_info['condition'] ) ) {

					if ( 'ip-list' === $option_info['condition'] ) {
						$condition = true;

						if ( ! empty( $option_info['value'] ) ) {
							$option_info['value'] = array_filter( array_map( 'trim', explode( "\n", $option_info['value'] ) ) );
							$option_info['value'] = array_unique(
								array_map(
									function ( $ip ) {
										return preg_match( '/\//', $ip ) ? $ip : $ip . '/32';
									},
									$option_info['value']
								)
							);
						} else {
							$option_info['value'] = array();
						}
					} elseif ( 'api-keys' === $option_info['condition'] ) {
						$inputs = json_decode( $option_info['value'], true );
						$prefix = '';

						if ( 'upserv_package_private_api_keys' === $option_name ) {
							$prefix = 'UPDATEPULSE_P_';
						} elseif ( 'upserv_license_private_api_keys' === $option_name ) {
							$prefix = 'UPDATEPULSE_L_';
						}

						if ( empty( $option_info['value'] ) || json_last_error() ) {
							$option_info['value'] = '{}';
						} else {
							$filtered = array();

							foreach ( $inputs as $id => $values ) {
								$id = filter_var( $id, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
								$id = preg_replace( '/^' . preg_quote( $prefix, '/' ) . '/', '', $id );

								if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $id ) ) {
									$id = false;
								} else {
									$id = $prefix . $id;
								}

								$access = filter_var(
									isset( $values['access'] ) ? $values['access'] : array(),
									FILTER_SANITIZE_FULL_SPECIAL_CHARS,
									FILTER_REQUIRE_ARRAY
								);
								$key    = filter_var(
									isset( $values['key'] ) ? $values['key'] : false,
									FILTER_SANITIZE_FULL_SPECIAL_CHARS
								);

								if ( ! $id || empty( $access ) || ! $key ) {
									$filtered = new stdClass();

									break;
								}

								$filtered[ $id ] = array(
									'key'    => $key,
									'access' => $access,
								);
							}

							$option_info['value'] = wp_json_encode(
								$filtered,
								JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
							);
						}
					}
				}

				$condition = apply_filters(
					'upserv_api_option_update',
					$condition,
					$option_name,
					$option_info,
					$options
				);

				if ( $condition ) {
					update_option( $option_name, $option_info['value'] );
				} else {
					$errors[ $option_name ] = sprintf(
						// translators: %1$s is the option display name, %2$s is the condition for update
						__( 'Option %1$s was not updated. Reason: %2$s', 'updatepulse-server' ),
						$option_info['display_name'],
						$option_info['failure_display_message']
					);
				}
			}
		} elseif (
			isset( $_REQUEST['upserv_plugin_options_handler_nonce'] ) &&
			! wp_verify_nonce( $_REQUEST['upserv_plugin_options_handler_nonce'], 'upserv_plugin_options' )
		) {
			$errors['general'] = __( 'There was an error validating the form. It may be outdated. Please reload the page.', 'updatepulse-server' );
		}

		if ( ! empty( $errors ) ) {
			$result = $errors;
		}

		do_action( 'upserv_api_options_updated', $errors );

		return $result;
	}

	protected function get_submitted_options() {
		return apply_filters(
			'upserv_submitted_api_config',
			array(
				'upserv_package_private_api_keys'         => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_package_private_api_keys', FILTER_UNSAFE_RAW ),
					'display_name'            => __( 'Package API Authentication Keys', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid payload', 'updatepulse-server' ),
					'condition'               => 'api-keys',
				),
				'upserv_package_private_api_ip_whitelist' => array(
					'value'     => filter_input( INPUT_POST, 'upserv_package_private_api_ip_whitelist', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'condition' => 'ip-list',
				),
				'upserv_license_private_api_keys'         => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_license_private_api_keys', FILTER_UNSAFE_RAW ),
					'display_name'            => __( 'Private API Authentication Key', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'condition'               => 'api-keys',
				),
				'upserv_license_private_api_ip_whitelist' => array(
					'value'     => filter_input( INPUT_POST, 'upserv_license_private_api_ip_whitelist', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'condition' => 'ip-list',
				),
			)
		);
	}
}
