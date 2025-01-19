<?php

namespace Anyape\UpdatePulse\Server\Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use WP_Error;
use Anyape\UpdatePulse\Server\Manager\Data_Manager;
use Anyape\UpdatePulse\Server\API\Update_API;

class Remote_Sources_Manager {

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {

			if ( get_option( 'upserv_use_remote_repository' ) ) {
				add_action( 'action_scheduler_init', array( $this, 'register_remote_check_scheduled_hooks' ), 10, 0 );
			} else {
				add_action( 'init', array( $this, 'clear_remote_check_scheduled_hooks' ), 10, 0 );
			}

			add_action( 'wp_ajax_upserv_force_clean', array( $this, 'force_clean' ), 10, 0 );
			add_action( 'wp_ajax_upserv_remote_repository_test', array( $this, 'remote_repository_test' ), 10, 0 );
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 15, 0 );
			add_filter( 'upserv_admin_tab_links', array( $this, 'upserv_admin_tab_links' ), 15, 1 );
			add_filter( 'upserv_admin_tab_states', array( $this, 'upserv_admin_tab_states' ), 15, 2 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	public function register_remote_check_scheduled_hooks() {
		$result = false;

		if ( ! Update_API::is_doing_api_request() ) {
			$slugs = $this->get_package_slugs();

			if ( ! empty( $slugs ) ) {
				$api         = Update_API::get_instance();
				$action_hook = array( $api, 'download_remote_package' );

				foreach ( $slugs as $slug ) {
					add_action( 'upserv_check_remote_' . $slug, $action_hook, 10, 3 );
					do_action(
						'upserv_registered_check_remote_schedule',
						$slug,
						'upserv_check_remote_' . $slug,
						$action_hook
					);
				}

				$result = true;
			}
		}

		return $result;
	}

	public function clear_remote_check_scheduled_hooks() {
		$result = false;

		if ( ! upserv_is_doing_update_api_request() ) {
			$slugs = $this->get_package_slugs();

			if ( ! empty( $slugs ) ) {

				foreach ( $slugs as $slug ) {
					$scheduled_hook = 'upserv_check_remote_' . $slug;

					as_unschedule_all_actions( $scheduled_hook );
					do_action( 'upserv_cleared_check_remote_schedule', $slug, $scheduled_hook );
				}

				$result = true;
			}
		}

		return $result;
	}

	public function admin_menu() {
		$function   = array( $this, 'plugin_page' );
		$page_title = __( 'UpdatePulse Server - Remote Sources', 'updatepulse-server' );
		$menu_title = __( 'Remote Sources', 'updatepulse-server' );
		$menu_slug  = 'upserv-page-remote-sources';

		add_submenu_page( 'upserv-page', $page_title, $menu_title, 'manage_options', $menu_slug, $function );
	}

	public function upserv_admin_tab_links( $links ) {
		$links['remote-sources'] = array(
			admin_url( 'admin.php?page=upserv-page-remote-sources' ),
			"<span class='dashicons dashicons-networking'></span> " . __( 'Remote Sources', 'updatepulse-server' ),
		);

		return $links;
	}

	public function upserv_admin_tab_states( $states, $page ) {
		$states['remote-sources'] = 'upserv-page-remote-sources' === $page;

		return $states;
	}

	public function force_clean() {
		$result = false;
		$type   = false;

		if ( isset( $_REQUEST['nonce'] ) && wp_verify_nonce( $_REQUEST['nonce'], 'upserv_plugin_options' ) ) {
			$type = filter_input( INPUT_POST, 'type', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

			if ( 'schedules' === $type ) {
				$result = $this->clear_remote_check_scheduled_hooks();

				if ( $result ) {
					$this->reschedule_remote_check_recurring_events(
						get_option( 'upserv_remote_repository_check_frequency', 'daily' )
					);
				}
			}
		}

		if ( $result && $type ) {
			wp_send_json_success();
		} elseif ( 'schedules' === $type ) {
			$error = new WP_Error(
				__METHOD__,
				__( 'Error - check the packages directory is readable and not empty', 'updatepulse-server' )
			);

			wp_send_json_error( $error );
		}
	}

	public function remote_repository_test() {
		$result = array();

		if ( isset( $_REQUEST['nonce'] ) && wp_verify_nonce( $_REQUEST['nonce'], 'upserv_plugin_options' ) ) {
			$data = filter_input( INPUT_POST, 'data', FILTER_SANITIZE_FULL_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY );

			if ( $data ) {
				$url         = $data['upserv_remote_repository_url'];
				$self_hosted = $data['upserv_remote_repository_self_hosted'];
				$credentials = $data['upserv_remote_repository_credentials'];
				$options     = array();
				$service     = false;
				$host        = wp_parse_url( $url, PHP_URL_HOST );
				$path        = wp_parse_url( $url, PHP_URL_PATH );
				$user_name   = false;

				if ( 'true' === $self_hosted ) {
					$service = 'GitLab';
				} else {
					$services = array(
						'github.com'    => 'GitHub',
						'bitbucket.org' => 'BitBucket',
						'gitlab.com'    => 'GitLab',
					);

					if ( isset( $services[ $host ] ) ) {
						$service = $services[ $host ];
					}
				}

				if ( 'BitBucket' === $service ) {
					wp_send_json_error(
						new WP_Error(
							__METHOD__,
							__( 'Error - Test Remote Repository Access is not supported for Bitbucket. Please save your settings and try to prime a package in the Overview page.', 'updatepulse-server' )
						)
					);
				}

				if ( preg_match( '@^/?(?P<username>[^/]+?)/?$@', $path, $matches ) ) {
					$user_name = $matches['username'];

					if ( 'GitHub' === $service ) {
						$url                = 'https://api.github.com/user'; //esc_url( 'https://api.github.com/orgs/' . $user_name . '/repos' );
						$options            = array( 'timeout' => 3 );
						$options['headers'] = array(
							'Authorization' => 'Basic '
								. base64_encode( $user_name . ':' . $credentials ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						);
					} elseif ( 'GitLab' === $service ) {
						$options = array( 'timeout' => 3 );
						$scheme  = wp_parse_url( $url, PHP_URL_SCHEME );
						$url     = sprintf(
							'%1$s://%2$s/api/v4/groups/%3$s/?private_token=%4$s',
							$scheme,
							$host,
							$user_name,
							$credentials
						);
					}
				}

				$response = wp_remote_get( $url, $options );

				if ( is_wp_error( $response ) ) {
					$result = $response;
				} else {
					$code = wp_remote_retrieve_response_code( $response );
					$body = wp_remote_retrieve_body( $response );

					if ( 200 === $code ) {
						$condition = false;

						if ( 'GitHub' === $service ) {
							$body      = json_decode( $body, true );
							$condition = trailingslashit(
								$data['upserv_remote_repository_url']
							) === trailingslashit(
								$body['html_url']
							);

							if ( ! $condition ) {
								$login    = $body['login'];
								$url      = esc_url( 'https://api.github.com/orgs/' . $user_name . '/members/' . $login );
								$response = wp_remote_get( $url, $options );

								if ( ! is_wp_error( $response ) ) {
									$code      = wp_remote_retrieve_response_code( $response );
									$condition = 204 === $code;
								}
							}
						}

						if ( 'GitLab' === $service ) {
							$body      = json_decode( $body, true );
							$condition = $user_name === $body['path'];
						}

						if ( $condition ) {
							$result[] = __( 'Remote Repository Service was reached sucessfully.', 'updatepulse-server' );
						} elseif ( 'GitHub' === $service && 200 !== $code && 204 !== $code ) {
							$result = new WP_Error(
								__METHOD__,
								__( 'Error - Please check the provided Remote Repository Service URL.', 'updatepulse-server' ) . "\n" . __( 'If you are using a fine-grained access token for an organisation, please check the provided token has the permissions to access members information.', 'updatepulse-server' )
							);
						} else {
							$result = new WP_Error(
								__METHOD__,
								__( 'Error - Please check the provided Remote Repository Service URL.', 'updatepulse-server' )
							);
						}
					} else {
						$result = new WP_Error(
							__METHOD__,
							__( 'Error - Please check the provided Remote Repository Service Credentials.', 'updatepulse-server' )
						);
					}
				}
			} else {
				$result = new WP_Error(
					__METHOD__,
					__( 'Error - Received invalid data ; please reload the page and try again.', 'updatepulse-server' )
				);
			}
		}

		if ( ! is_wp_error( $result ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	// Misc. -------------------------------------------------------

	public static function clear_schedules() {
		$manager = new self();

		return $manager->clear_remote_check_scheduled_hooks();
	}

	public static function register_schedules() {
		$manager = new self();
		$result  = false;

		if ( apply_filters( 'upserv_use_recurring_schedule', true ) ) {
			$frequency = get_option( 'upserv_remote_repository_check_frequency', 'daily' );
			$result    = $manager->reschedule_remote_check_recurring_events( $frequency );
		}

		return $result;
	}

	public function reschedule_remote_check_recurring_events( $frequency ) {

		if ( Update_API::is_doing_api_request() ) {
			return false;
		}

		$slugs = $this->get_package_slugs();

		if ( ! empty( $slugs ) ) {

			foreach ( $slugs as $slug ) {
				$hook      = 'upserv_check_remote_' . $slug;
				$params    = array( $slug, null, false );
				$frequency = apply_filters( 'upserv_check_remote_frequency', $frequency, $slug );
				$timestamp = time();
				$schedules = wp_get_schedules();

				as_unschedule_all_actions( $hook );
				do_action( 'upserv_cleared_check_remote_schedule', $slug, $hook );

				$result = as_schedule_recurring_action(
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

			return true;
		}

		return false;
	}

	public function plugin_page() {

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Sorry, you are not allowed to access this page.' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		wp_cache_set( 'settings_notice', $this->plugin_options_handler(), 'upserv' );

		$registered_schedules = wp_get_schedules();
		$schedules            = array();
		$repo_config          = upserv_get_option( 'remote_repositories', array() );
		$idx                  = empty( $repo_config ) ? false : array_key_first( $repo_config );
		$repo_config          = ( $idx ) ? $repo_config[ $idx ] : false;
		$options              = array(
			'use_remote_repositories' => upserv_get_option( 'use_remote_repositories', 0 ),
			'url'                     => ( $idx ) ? $repo_config['url'] : '',
			'self_hosted'             => ( $idx ) ? $repo_config['self_hosted'] : 0,
			'branch'                  => ( $idx ) ? $repo_config['branch'] : '',
			'credentials'             => ( $idx ) ? $repo_config['credentials'] : '',
			'filter_packages'         => ( $idx ) ? $repo_config['filter_packages'] : 0,
			'check_frequency'         => ( $idx ) ? $repo_config['check_frequency'] : 'daily',
		);

		foreach ( $registered_schedules as $key => $schedule ) {
			$schedules[ $schedule['display'] ] = array(
				'slug' => $key,
			);
		}

		upserv_get_admin_template(
			'plugin-remote-sources-page.php',
			array(
				'options'              => $options,
				'packages_dir'         => Data_Manager::get_data_dir( 'packages' ),
				'registered_schedules' => $registered_schedules,
				'schedules'            => $schedules,
				'hide_check_frequency' => ! apply_filters(
					'upserv_use_recurring_schedule',
					true
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
		$original_upserv_remote_repository_check_frequency = get_option( 'upserv_remote_repository_check_frequency', 'daily' );
		$new_upserv_remote_repository_check_frequency      = null;
		$original_upserv_use_remote_repository             = get_option( 'upserv_use_remote_repository' );
		$new_upserv_use_remote_repository                  = null;

		if (
			isset( $_REQUEST['upserv_plugin_options_handler_nonce'] ) &&
			wp_verify_nonce( $_REQUEST['upserv_plugin_options_handler_nonce'], 'upserv_plugin_options' )
		) {
			$result  = __( 'UpdatePulse Server options successfully updated', 'updatepulse-server' );
			$options = $this->get_submitted_options();

			foreach ( $options as $option_name => $option_info ) {
				$condition = $option_info['value'];
				$skip      = false;

				if ( ! $skip && isset( $option_info['condition'] ) ) {

					if ( 'boolean' === $option_info['condition'] ) {
						$condition            = true;
						$option_info['value'] = ( $option_info['value'] );
					}

					if ( 'known frequency' === $option_info['condition'] ) {
						$schedules      = wp_get_schedules();
						$schedule_slugs = array_keys( $schedules );
						$condition      = $condition && in_array( $option_info['value'], $schedule_slugs, true );
					}

					if ( 'service_url' === $condition ) {
						$repo_regex = '@^/?([^/]+?)/([^/#?&]+?)/?$@';
						$path       = wp_parse_url( $option_info['value'], PHP_URL_PATH );
						$condition  = (bool) preg_match( $repo_regex, $path );
					}

					$condition = apply_filters(
						'upserv_remote_source_option_update',
						$condition,
						$option_name,
						$option_info,
						$options
					);
				}

				if (
					! $skip &&
					isset( $option_info['dependency'] ) &&
					! $options[ $option_info['dependency'] ]['value']
				) {
					$skip      = true;
					$condition = false;
				}

				if ( ! $skip && $condition ) {

					$to_save[ $option_info['path'] ] = $option_info['value'];

					if ( 'upserv_remote_repository_check_frequency' === $option_name ) {
						$new_upserv_remote_repository_check_frequency = $option_info['value'];
					}

					if ( 'upserv_use_remote_repository' === $option_name ) {
						$new_upserv_use_remote_repository = $option_info['value'];
					}
				} elseif ( ! $skip ) {
					$errors[ $option_name ] = sprintf(
						// translators: %1$s is the option display name, %2$s is the condition for update
						__( 'Option %1$s was not updated. Reason: %2$s', 'updatepulse-server' ),
						$option_info['display_name'],
						$option_info['failure_display_message']
					);
				}
			}

			if ( ! empty( $to_save ) ) {
				$to_update = upserv_set_option( 'use_remote_repositories', $to_save['use_remote_repositories'] );

				unset( $to_save['use_remote_repositories'] );

				$idx             = str_replace( '/', '|', base64_encode( $to_save['url'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$options         = array( $idx => array() );
				$options[ $idx ] = $to_save;
				$to_update       = upserv_set_option( 'remote_repositories', $options );

				upserv_update_options( $to_update );
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

		if ( apply_filters( 'upserv_use_recurring_schedule', true ) ) {

			if (
				null !== $new_upserv_use_remote_repository &&
				$new_upserv_use_remote_repository !== $original_upserv_use_remote_repository
			) {

				if ( ! $original_upserv_use_remote_repository && $new_upserv_use_remote_repository ) {
					$this->reschedule_remote_check_recurring_events(
						get_option( 'upserv_remote_repository_check_frequency', 'daily' )
					);
				} elseif (
					$original_upserv_use_remote_repository &&
					! $new_upserv_use_remote_repository
				) {
					$this->clear_remote_check_scheduled_hooks();
				}
			}

			if (
				null !== $new_upserv_remote_repository_check_frequency &&
				$new_upserv_remote_repository_check_frequency !== $original_upserv_remote_repository_check_frequency
			) {
				$this->reschedule_remote_check_recurring_events(
					$new_upserv_remote_repository_check_frequency
				);
			}

			if ( apply_filters( 'upserv_need_reschedule_remote_check_recurring_events', false ) ) {
				$this->reschedule_remote_check_recurring_events(
					$new_upserv_remote_repository_check_frequency
				);
			}
		} else {
			$this->clear_remote_check_scheduled_hooks();
			set_transient( 'upserv_flush', 1, 60 );
		}

		do_action( 'upserv_remote_sources_options_updated', $errors );

		return $result;
	}

	protected function get_submitted_options() {
		return apply_filters(
			'upserv_submitted_remote_sources_config',
			array(
				'upserv_use_remote_repository'             => array(
					'value'        => filter_input( INPUT_POST, 'upserv_use_remote_repository', FILTER_VALIDATE_BOOLEAN ),
					'display_name' => __( 'Use a Remote Repository Service', 'updatepulse-server' ),
					'condition'    => 'boolean',
					'path'         => 'use_remote_repositories',
				),
				'upserv_remote_repository_url'             => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_remote_repository_url', FILTER_VALIDATE_URL ),
					'display_name'            => __( 'Remote Repository Service URL', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid URL', 'updatepulse-server' ),
					'dependency'              => 'upserv_use_remote_repository',
					'condition'               => 'service_url',
					'path'                    => 'url',
				),
				'upserv_remote_repository_self_hosted'     => array(
					'value'        => filter_input( INPUT_POST, 'upserv_remote_repository_self_hosted', FILTER_VALIDATE_BOOLEAN ),
					'display_name' => __( 'Self-hosted Remote Repository Service', 'updatepulse-server' ),
					'condition'    => 'boolean',
					'path'         => 'self_hosted',
				),
				'upserv_remote_repository_branch'          => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_remote_repository_branch', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Packages Branch Name', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'path'                    => 'branch',
				),
				'upserv_remote_repository_credentials'     => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_remote_repository_credentials', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Remote Repository Service Credentials', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid string', 'updatepulse-server' ),
					'path'                    => 'credentials',
				),
				'upserv_remote_repository_filter_packages' => array(
					'value'        => filter_input( INPUT_POST, 'upserv_remote_repository_filter_packages', FILTER_VALIDATE_BOOLEAN ),
					'display_name' => __( 'Filter Packages', 'updatepulse-server' ),
					'condition'    => 'boolean',
					'path'         => 'filter_packages',
				),
				'upserv_remote_repository_check_frequency' => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_remote_repository_check_frequency', FILTER_SANITIZE_FULL_SPECIAL_CHARS ),
					'display_name'            => __( 'Remote Update Check Frequency', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid option', 'updatepulse-server' ),
					'condition'               => 'known frequency',
					'path'                    => 'check_frequency',
				),
			)
		);
	}

	protected function get_package_slugs() {
		$slugs = wp_cache_get( 'package_slugs', 'updatepulse-server' );

		if ( false === $slugs ) {
			$slugs             = array();
			$package_directory = Data_Manager::get_data_dir( 'packages' );

			if ( is_dir( $package_directory ) ) {
				$package_paths = glob( trailingslashit( $package_directory ) . '*.zip' );

				if ( ! empty( $package_paths ) ) {

					foreach ( $package_paths as $package_path ) {
						$package_path_parts = explode( '/', $package_path );
						$slugs[]            = str_replace( '.zip', '', end( $package_path_parts ) );
					}
				}
			}

			$slugs = apply_filters( 'upserv_remote_sources_manager_get_package_slugs', $slugs );

			wp_cache_set( 'package_slugs', $slugs, 'updatepulse-server' );
		}

		return $slugs;
	}
}
