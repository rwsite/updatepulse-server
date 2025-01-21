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

			if ( upserv_get_option( 'use_remote_repositories' ) ) {
				add_action( 'action_scheduler_init', array( $this, 'register_remote_check_scheduled_hooks' ), 10, 0 );
			} else {
				add_action( 'init', array( $this, 'clear_remote_check_scheduled_hooks' ), 10, 0 );
			}

			add_action( 'wp_ajax_upserv_force_clean', array( $this, 'force_clean' ), 10, 0 );
			add_action( 'wp_ajax_upserv_remote_repository_test', array( $this, 'remote_repository_test' ), 10, 0 );
			add_action( 'admin_menu', array( $this, 'admin_menu' ), 15, 0 );

			add_filter( 'upserv_admin_scripts', array( $this, 'upserv_admin_scripts' ), 10, 1 );
			add_filter( 'upserv_admin_styles', array( $this, 'upserv_admin_styles' ), 10, 1 );
			add_filter( 'upserv_admin_tab_links', array( $this, 'upserv_admin_tab_links' ), 15, 1 );
			add_filter( 'upserv_admin_tab_states', array( $this, 'upserv_admin_tab_states' ), 15, 2 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	public function upserv_admin_scripts( $scripts ) {
		$scripts['remote_sources'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'js/admin/remote-sources' . upserv_assets_suffix() . '.js',
			'uri'  => UPSERV_PLUGIN_URL . 'js/admin/remote-sources' . upserv_assets_suffix() . '.js',
			'deps' => array( 'jquery' ),
		);

		return $scripts;
	}

	public function upserv_admin_styles( $styles ) {
		$styles['remote_sources'] = array(
			'path' => UPSERV_PLUGIN_PATH . 'css/admin/remote-sources' . upserv_assets_suffix() . '.css',
			'uri'  => UPSERV_PLUGIN_URL . 'css/admin/remote-sources' . upserv_assets_suffix() . '.css',
		);

		return $styles;
	}

	// TODO
	public function register_remote_check_scheduled_hooks() {

		if ( upserv_is_doing_update_api_request() ) {
			return;
		}

		$repo_configs = upserv_get_option( 'remote_repositories', array() );

		if ( empty( $repo_configs ) ) {
			return;
		}
		$slugs = array();

		foreach ( $repo_configs as $r_c ) {

			if ( $r_c['use_webhooks'] || ! isset( $r_c['url'] ) ) {
				continue;
			}

			$slugs = $this->get_package_slugs( $r_c['url'] );

			if ( empty( $slugs ) ) {
				continue;
			}

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
		}
	}

	public function clear_remote_check_scheduled_hooks() {

		if ( upserv_is_doing_update_api_request() ) {
			return false;
		}

		$repo_configs = upserv_get_option( 'remote_repositories', array() );

		if ( ! empty( $repo_configs ) ) {

			foreach ( $repo_configs as $r_c ) {

				if ( ! isset( $r_c['url'] ) ) {
					continue;
				}

				$slugs = $this->get_package_slugs( $r_c['url'] );

				if ( ! empty( $slugs ) ) {

					foreach ( $slugs as $slug ) {
						$scheduled_hook = 'upserv_check_remote_' . $slug;

						as_unschedule_all_actions( $scheduled_hook );
						do_action( 'upserv_cleared_check_remote_schedule', $slug, $scheduled_hook );
					}
				}
			}
		}

		return true;
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
					$repo_configs = upserv_get_option( 'remote_repositories', array() );

					if ( ! empty( $repo_configs ) ) {

						foreach ( $repo_configs as $r_c ) {
							$check_frequency = isset( $r_c['check_frequency'] ) ? $r_c['check_frequency'] : 'daily';

							$this->reschedule_remote_check_recurring_events( $check_frequency, $r_c );
						}
					}
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

	// TODO
	public static function register_schedules() {
		$manager = new self();

		// TODO
		if ( apply_filters( 'upserv_use_recurring_schedule', true ) ) {
			$repo_configs = upserv_get_option( 'remote_repositories', array() );

			if ( ! empty( $repo_configs ) ) {

				foreach ( $repo_configs as $r_c ) {
					$check_frequency = isset( $r_c['check_frequency'] ) ? $r_c['check_frequency'] : 'daily';
					// TODO
					$manager->reschedule_remote_check_recurring_events( $check_frequency, $r_c );
				}
			}
		}
	}

	// TODO
	public function reschedule_remote_check_recurring_events( $frequency, $repo_config ) {

		if ( upserv_is_doing_update_api_request() ) {
			return false;
		}

		if ( ! isset( $repo_config['url'], $repo_config['use_webhooks'] ) ) {
			return false;
		}

		if ( ! $repo_config['use_webhooks'] ) {
			$slugs = $this->get_package_slugs( $repo_config['url'] );

			if ( ! empty( $slugs ) ) {

				foreach ( $slugs as $slug ) {
					$meta      = upserv_get_package_metadata( $slug );
					$type      = isset( $meta['type'] ) ? $meta['type'] : null;
					$hook      = 'upserv_check_remote_' . $slug;
					$params    = array( $slug, $type, false );
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
		$repo_configs         = upserv_get_option( 'remote_repositories', array() );
		$options              = array(
			'use_remote_repositories' => upserv_get_option( 'use_remote_repositories', 0 ),
			'repositories'            => wp_json_encode( $repo_configs ),
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
			)
		);
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected function plugin_options_handler() {
		$errors                         = array();
		$result                         = '';
		$to_save                        = array();
		$repo_configs                   = upserv_get_option( 'remote_repositories', array() );
		$idx                            = empty( $repo_configs ) ? false : array_key_first( $repo_configs );
		$repo_config                    = ( $idx ) ? $repo_configs[ $idx ] : false;
		$original_check_frequency       = ( $idx ) ? $repo_config['check_frequency'] : 'daily';
		$new_check_frequency            = null;
		$original_use_remote_repository = upserv_get_option( 'use_remote_repositories' );
		$new_use_remote_repository      = null;

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

			if ( isset( $option_info['condition'] ) && 'repositories' === $option_info['condition'] ) {
				$inputs = json_decode( $option_info['value'], true );

				if ( ! is_array( $inputs ) ) {
					$inputs = upserv_get_option( 'remote_repositories' );
				} else {
					$filtered = array();
					$index    = 0;

					// "url": "https:\/\/github.com\/test\/",
					// "branch": "main",
					// "self_hosted": 0,
					// "credentials": "github_pat_11AObNUuLX5BvkUXIhAH5JECWVa5XySENb",
					// "filter_packages": 1,
					// "check_frequency": "daily",
					// "use_webhooks": 1,
					// "check_delay": 0,
					// "webhook_secret": "asecretremotehook"

					foreach ( $inputs as $id => $values ) {
						$id           = filter_var( $id, FILTER_SANITIZE_FULL_SPECIAL_CHARS );
						$decoded      = $id ? base64_decode( str_replace( '|', '/', $id ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
						$decoded      = $decoded ? explode( '|', $decoded ) : array();
						$url_check    = 2 === count( $decoded ) ? $decoded[0] : false;
						$branch_check = 2 === count( $decoded ) ? $decoded[1] : false;
						$url          = filter_var( $values['url'], FILTER_VALIDATE_URL );
						$branch       = filter_var( $values['branch'], FILTER_SANITIZE_FULL_SPECIAL_CHARS );

						if ( ! $url_check || ! $branch_check || $url_check !== $url || $branch_check !== $branch ) {

							if ( ! isset( $errors[ $option_name ] ) ) {
								$errors[ $option_name ] = array();
							}

							$errors[ $option_name ][] = sprintf(
								// translators: %d is the index of the item in the list
								__( 'Invalid URL or Branch for item at index %d', 'updatepulse-server' ),
								$index
							);

							continue;
						}

						$self_hosted       = intval( filter_var( $values['self_hosted'], FILTER_VALIDATE_BOOLEAN ) );
						$credentials       = filter_var( $values['credentials'], FILTER_SANITIZE_FULL_SPECIAL_CHARS );
						$filter_packages   = intval( filter_var( $values['filter_packages'], FILTER_VALIDATE_BOOLEAN ) );
						$check_frequency   = filter_var( $values['check_frequency'], FILTER_SANITIZE_FULL_SPECIAL_CHARS );
						$use_webhooks      = intval( filter_var( $values['use_webhooks'], FILTER_VALIDATE_BOOLEAN ) );
						$check_delay       = intval( filter_var( $values['check_delay'], FILTER_VALIDATE_INT ) );
						$webhook_secret    = filter_var( $values['webhook_secret'], FILTER_SANITIZE_FULL_SPECIAL_CHARS );
						$known_frequencies = wp_get_schedules();
						$known_frequencies = array_keys( $known_frequencies );

						if ( ! in_array( $check_frequency, $known_frequencies, true ) ) {
							$check_frequency = 'daily';
						}

						$recomputed_id              = str_replace( '/', '|', base64_encode( $url . '|' . $branch ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
						$filtered[ $recomputed_id ] = array(
							'url'             => $url,
							'branch'          => $branch,
							'self_hosted'     => $self_hosted,
							'credentials'     => $credentials,
							'filter_packages' => $filter_packages,
							'check_frequency' => $check_frequency,
							'use_webhooks'    => $use_webhooks,
							'check_delay'     => $check_delay,
							'webhook_secret'  => $webhook_secret,
						);

						++$index;
					}

					$option_info['value'] = $filtered;
				}
			} elseif ( isset( $option_info['condition'] ) && 'boolean' === $option_info['condition'] ) {
				$condition            = true;
				$option_info['value'] = ( $option_info['value'] );
			}

			$condition = apply_filters(
				'upserv_remote_source_option_update',
				$condition,
				$option_name,
				$option_info,
				$options
			);

			if ( $condition ) {
				$to_save[ $option_info['path'] ] = apply_filters(
					'upserv_remote_sources_option_save_value',
					$option_info['value'],
					$option_name,
					$option_info,
					$options
				);
				$to_save[ $option_info['path'] ] = $option_info['value'];
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
			$to_update = upserv_set_option( 'use_remote_repositories', $to_save['use_remote_repositories'] );

			unset( $to_save['use_remote_repositories'] );

			$idx             = str_replace( '/', '|', base64_encode( $to_save['url'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$options         = array( $idx => array() );
			$options[ $idx ] = $to_save;
			$to_update       = upserv_set_option( 'remote_repositories', $options );

			upserv_update_options( $to_update );
		}

		if ( ! empty( $errors ) ) {
			$result = $errors;
		}

		// TODO
		if ( apply_filters( 'upserv_use_recurring_schedule', true ) ) {

			if (
				null !== $new_use_remote_repository &&
				$new_use_remote_repository !== $original_use_remote_repository
			) {

				if ( ! $original_use_remote_repository && $new_use_remote_repository ) {
					$this->reschedule_remote_check_recurring_events(
						$repo_config['check_frequency'],
						$repo_config
					);
				} elseif ( $original_use_remote_repository && ! $new_use_remote_repository ) {
					$this->clear_remote_check_scheduled_hooks();
				}
			}

			if (
				null !== $new_check_frequency &&
				$new_check_frequency !== $original_check_frequency
			) {
				$this->reschedule_remote_check_recurring_events(
					$new_check_frequency,
					$repo_config
				);
			}

			if ( apply_filters( 'upserv_need_reschedule_remote_check_recurring_events', false ) ) {
				$this->reschedule_remote_check_recurring_events(
					$new_check_frequency,
					$repo_config
				);
			}
		} else {
			$this->clear_remote_check_scheduled_hooks();
			set_transient( 'upserv_flush', 1, 60 );
		}

		do_action( 'upserv_remote_sources_options_updated', $result );

		return $result;
	}

	protected function get_submitted_options() {
		return apply_filters(
			'upserv_submitted_remote_sources_config',
			array(
				'upserv_use_remote_repository' => array(
					'value'        => filter_input( INPUT_POST, 'upserv_use_remote_repository', FILTER_VALIDATE_BOOLEAN ),
					'display_name' => __( 'Use a Remote Repository Service', 'updatepulse-server' ),
					'condition'    => 'boolean',
					'path'         => 'use_remote_repositories',
				),
				'upserv_repositories'          => array(
					'value'                   => filter_input( INPUT_POST, 'upserv_repositories', FILTER_UNSAFE_RAW ),
					'display_name'            => __( 'Remote Repository Services', 'updatepulse-server' ),
					'failure_display_message' => __( 'Not a valid payload', 'updatepulse-server' ),
					'condition'               => 'repositories',
					'path'                    => 'repositories',
				),
			)
		);
	}

	protected function get_package_slugs( $repo_url ) {
		$slugs = wp_cache_get( 'package_slugs', 'updatepulse-server' );

		if ( false === $slugs ) {
			$slugs    = array();
			$meta_dir = Data_Manager::get_data_dir( 'metadata' );

			if ( is_dir( $meta_dir ) ) {
				$meta_paths = glob( trailingslashit( $meta_dir ) . '*.json' );

				if ( ! empty( $meta_paths ) ) {

					foreach ( $meta_paths as $meta_path ) {
						$meta_path_parts = explode( '/', $meta_path );
						$slugs[]         = str_replace( '.json', '', end( $meta_path_parts ) );
					}
				}
			}

			if ( empty( $slugs ) ) {

				foreach ( $slugs as $idx => $slug ) {
					$meta = upserv_get_package_metadata( $slug );
					$mode = upserv_get_option( 'use_cloud_storage' ) ? 'cloud' : 'local';

					if (
						! isset( $meta['vcs'] ) ||
						trailingslashit( $meta['vcs'] ) !== trailingslashit( $repo_url ) ||
						! isset( $meta['whitelisted'] ) ||
						! isset( $meta['whitelisted'][ $mode ] ) ||
						! $meta['whitelisted'][ $mode ]
					) {
						unset( $slugs[ $idx ] );
					}
				}
			}

			wp_cache_set( 'package_slugs', $slugs, 'updatepulse-server' );
		}

		return $slugs;
	}
}
