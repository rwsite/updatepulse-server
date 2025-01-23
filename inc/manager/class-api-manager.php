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
		$l10n = array(
			'deleteApiKeyConfirm'           => array(
				__( 'You are about to delete an API key.', 'updatepulse-server' ),
				__( 'If you proceed, the remote systems using it will not be able to access the API anymore.', 'updatepulse-server' ),
				"\n",
				__( 'Are you sure you want to do this?', 'updatepulse-server' ),
			),
			'deleteApiWebhookConfirm'       => array(
				__( 'You are about to delete a Webhook.', 'updatepulse-server' ),
				__( 'If you proceed, the Payload URL will not receive the configured events anymore.', 'updatepulse-server' ),
				"\n",
				__( 'Are you sure you want to do this?', 'updatepulse-server' ),
			),
			'addWebhookNoLicenseApiConfirm' => array(
				__( 'You are about to add a Webhook without License API Key ID.', 'updatepulse-server' ),
				__( 'If you proceed, the Payload URL will receive events for ALL licenses.', 'updatepulse-server' ),
				"\n",
				__( 'Are you sure you want to do this?', 'updatepulse-server' ),
			),
			'actionApiCountSingular'        => __( '1 action', 'updatepulse-server' ),
			'actionApiCountSingularOther'   => __( '1 action (all records)', 'updatepulse-server' ),
			// translators: %d is the number of actions
			'actionApiCountPlural'          => __( '%d actions', 'updatepulse-server' ),
			// translators: %d is the number of actions
			'actionApiCountPluralOther'     => __( '%d actions (all records)', 'updatepulse-server' ),
			'actionApiCountAll'             => __( 'All actions', 'updatepulse-server' ),
			'actionApiCountAllOther'        => __( 'All actions (all records)', 'updatepulse-server' ),
			'eventApiCountAll'              => __( 'All events', 'updatepulse-server' ),
			// translators: %s is the type of events
			'eventApiCountAllType'          => __( 'All %s events', 'updatepulse-server' ),
			// translators: %s is the type of event
			'eventApiCountTypeSingular'     => __( '1 %s event', 'updatepulse-server' ),
			// translators: %1$d is the number of events, %s is the type of events
			'eventApiCountTypePlural'       => __( '%1$d %2$s events', 'updatepulse-server' ),
			'eventApiTypePackage'           => _x( 'package', 'UpdatePulse Server webhook event type', 'updatepulse-server' ),
			'eventApiTypeLicense'           => _x( 'license', 'UpdatePulse Server webhook event type', 'updatepulse-server' ),
			// translators: the separator between summaries; example: All package events, 3 license events
			'apiSumSep'                     => _x( ', ', 'UpdatePulse Server separator between API summaries', 'updatepulse-server' ),
		);

		$l10n           = apply_filters( 'upserv_scripts_l10n', $l10n, 'api' );
		$scripts['api'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'js/admin/api' . upserv_assets_suffix() . '.js',
			'uri'  => UPSERV_PLUGIN_URL . 'js/admin/api' . upserv_assets_suffix() . '.js',
			'deps' => array( 'jquery' ),
			'l10n' => array(
				'values' => $l10n,
			),
		);

		return $scripts;
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
			'<i class="fa-solid fa-share-nodes"></i>' . __( 'API & Webhooks', 'updatepulse-server' ),
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

		$options = array(
			'package_private_api_keys'         => wp_json_encode(
				upserv_get_option(
					'api/packages/private_api_keys',
					(object) array()
				)
			),
			'package_private_api_ip_whitelist' => upserv_get_option(
				'api/packages/private_api_ip_whitelist',
				array()
			),
			'license_private_api_keys'         => wp_json_encode(
				upserv_get_option(
					'api/licenses/private_api_keys',
					(object) array()
				)
			),
			'license_private_api_ip_whitelist' => upserv_get_option(
				'api/licenses/private_api_ip_whitelist',
				array()
			),
			'webhooks'                         => wp_json_encode(
				upserv_get_option(
					'api/webhooks',
					(object) array(),
				)
			),
		);

		upserv_get_admin_template(
			'plugin-api-page.php',
			array(
				'options'             => $options,
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
		$errors  = array();
		$result  = '';
		$to_save = array();

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

		foreach ( $options as $option_name => $option_info ) {
			$condition = $option_info['value'];

			if ( isset( $option_info['condition'] ) && 'ip-list' === $option_info['condition'] ) {
				$condition = true;

				if ( empty( $option_info['value'] ) ) {
					$option_info['value'] = array();
				} else {
					$option_info['value'] = array_filter( array_map( 'trim', explode( "\n", $option_info['value'] ) ) );
					$option_info['value'] = array_unique(
						array_map(
							function ( $ip ) {
								return preg_match( '/\//', $ip ) ? $ip : $ip . '/32';
							},
							$option_info['value']
						)
					);
				}
			} elseif ( isset( $option_info['condition'] ) && 'api-keys' === $option_info['condition'] ) {
				$inputs = json_decode( $option_info['value'], true );
				$prefix = '';

				if ( 'upserv_package_private_api_keys' === $option_name ) {
					$prefix = 'UPDATEPULSE_P_';
				} elseif ( 'upserv_license_private_api_keys' === $option_name ) {
					$prefix = 'UPDATEPULSE_L_';
				}

				if ( empty( $option_info['value'] ) || json_last_error() ) {
					$option_info['value'] = (object) array();
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

					$option_info['value'] = $filtered;
				}
			} elseif ( 'webhooks' === $option_info['condition'] ) {
				$inputs = json_decode( $option_info['value'], true );

				if ( empty( $option_info['value'] ) || json_last_error() ) {
					$option_info['value'] = (object) array();
				} else {
					$filtered = array();

					foreach ( $inputs as $index => $values ) {
						$check_url       = base64_decode( str_replace( '|', '/', $index ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						$url             = filter_var(
							isset( $values['url'] ) ? $values['url'] : false,
							FILTER_SANITIZE_URL
						);
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
							isset( $values['licenseAPIKey'] ) ? $values['licenseAPIKey'] : false,
							FILTER_SANITIZE_FULL_SPECIAL_CHARS
						);

						if ( ! $url || $check_url !== $url || empty( $events ) || ! $secret ) {
							$filtered = (object) array();

							break;
						}

						$filtered[ $index ] = array(
							'url'           => $url,
							'secret'        => $secret,
							'events'        => $events,
							'licenseAPIKey' => $license_api_key,
						);
					}

					$option_info['value'] = $filtered;
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
				$to_save[ $option_info['path'] ] = apply_filters(
					'upserv_api_option_save_value',
					$option_info['value'],
					$option_name,
					$option_info,
					$options
				);
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
					'path'                    => 'api/packages/private_api_keys',
				),
				'upserv_package_private_api_ip_whitelist' => array(
					'value'     => filter_input( INPUT_POST, 'upserv_package_private_api_ip_whitelist', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'condition' => 'ip-list',
					'path'      => 'api/packages/private_api_ip_whitelist',
				),
				'upserv_license_private_api_keys'         => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_license_private_api_keys', FILTER_UNSAFE_RAW ),
					'display_name'            => __( 'Private API Authentication Key', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid payload', 'updatepulse-server' ),
					'condition'               => 'api-keys',
					'path'                    => 'api/licenses/private_api_keys',
				),
				'upserv_license_private_api_ip_whitelist' => array(
					'value'     => filter_input( INPUT_POST, 'upserv_license_private_api_ip_whitelist', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'condition' => 'ip-list',
					'path'      => 'api/licenses/private_api_ip_whitelist',
				),
				'upserv_webhooks'                         => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_webhooks', FILTER_UNSAFE_RAW ),
					'display_name'            => __( 'Webhooks', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid payload', 'updatepulse-server' ),
					'condition'               => 'webhooks',
					'path'                    => 'api/webhooks',
				),
			)
		);
	}
}
