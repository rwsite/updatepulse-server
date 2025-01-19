<?php

namespace Anyape\UpdatePulse\Server\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use DateTimeZone;
use DateTime;
use WP_Error;
use Anyape\UpdatePulse\Server\Manager\Data_Manager;

class Webhook_API {

	protected static $doing_update_api_request = null;
	protected static $instance;
	protected static $config;

	protected $webhooks;
	protected $http_response_code = 200;

	public function __construct( $init_hooks = false ) {
		$this->webhooks = json_decode( get_option( 'upserv_webhooks', '{}' ), true );

		if ( $init_hooks && get_option( 'upserv_remote_repository_use_webhooks' ) ) {

			if ( ! self::is_doing_api_request() ) {
				add_action( 'init', array( $this, 'add_endpoints' ), 10, 0 );
			}

			add_action( 'parse_request', array( $this, 'parse_request' ), -99, 0 );
			add_action( 'upserv_webhook_invalid_request', array( $this, 'upserv_webhook_invalid_request' ), 10, 0 );

			add_filter( 'query_vars', array( $this, 'query_vars' ), -99, 1 );
			add_filter( 'upserv_webhook_process_request', array( $this, 'upserv_webhook_process_request' ), 10, 2 );
		}

		add_action( 'upserv_webhook', array( $this, 'fire_webhook' ), 10, 4 );
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	// WordPress hooks ---------------------------------------------

	public function add_endpoints() {
		add_rewrite_rule( '^updatepulse-server-webhook$', 'index.php?__upserv_webhook=1&', 'top' );
		add_rewrite_rule(
			'^updatepulse-server-webhook/(plugin|theme|generic)/(.+)?$',
			'index.php?type=$matches[1]&package_id=$matches[2]&__upserv_webhook=1&',
			'top'
		);
	}

	public function parse_request() {
		global $wp;

		if ( isset( $wp->query_vars['__upserv_webhook'] ) ) {
			$this->handle_api_request();

			exit;
		}
	}

	public function query_vars( $query_vars ) {
		$query_vars = array_merge(
			$query_vars,
			array(
				'__upserv_webhook',
				'package_id',
				'type',
			)
		);

		return $query_vars;
	}

	public function upserv_webhook_invalid_request() {

		if ( ! isset( $_SERVER['SERVER_PROTOCOL'] ) || '' === $_SERVER['SERVER_PROTOCOL'] ) {
			$protocol = 'HTTP/1.1';
		} else {
			$protocol = $_SERVER['SERVER_PROTOCOL'];
		}

		header( $protocol . ' 401 Unauthorized' );

		upserv_get_template(
			'error-page.php',
			array(
				'title'   => __( '401 Unauthorized', 'updatepulse-server' ),
				'heading' => __( '401 Unauthorized', 'updatepulse-server' ),
				'message' => __( 'Invalid signature', 'updatepulse-server' ),
			)
		);

		exit( -1 );
	}

	public function upserv_webhook_process_request( $process, $payload ) {
		$payload = json_decode( $payload, true );

		if ( ! $payload ) {
			return false;
		}

		$branch = false;
		$config = self::get_config();

		if (
			( isset( $payload['object_kind'] ) && 'push' === $payload['object_kind'] ) ||
			( isset( $_SERVER['HTTP_X_GITHUB_EVENT'] ) && 'push' === $_SERVER['HTTP_X_GITHUB_EVENT'] )
		) {
			$branch = str_replace( 'refs/heads/', '', $payload['ref'] );
		} elseif ( isset( $payload['push'], $payload['push']['changes'] ) ) {
			$branch = str_replace(
				'refs/heads/',
				'',
				$payload['push']['changes'][0]['new']['name']
			);
		}

		$process = $branch === $config['repository_branch'];

		return $process;
	}

	// Misc. -------------------------------------------------------

	public static function is_doing_api_request() {

		if ( null === self::$doing_update_api_request ) {
			self::$doing_update_api_request = ( false !== strpos( $_SERVER['REQUEST_URI'], 'updatepulse-server-webhook' ) );
		}

		return self::$doing_update_api_request;
	}

	public static function get_config() {

		if ( ! self::$config ) {
			$config = array(
				'use_webhooks'           => (bool) get_option( 'upserv_remote_repository_use_webhooks' ),
				'repository_branch'      => get_option( 'upserv_remote_repository_branch', 'main' ),
				'repository_check_delay' => intval( get_option( 'upserv_remote_repository_check_delay', 0 ) ),
				'webhook_secret'         => get_option( 'upserv_remote_repository_webhook_secret' ),
			);

			if (
				! is_numeric( $config['repository_check_delay'] ) &&
				0 <= intval( $config['repository_check_delay'] )
			) {
				$config['repository_check_delay'] = 0;

				update_option( 'upserv_remote_repository_check_delay', 0 );
			}

			if ( empty( $config['webhook_secret'] ) ) {
				$config['webhook_secret'] = bin2hex( openssl_random_pseudo_bytes( 16 ) );

				update_option( 'upserv_remote_repository_webhook_secret', $config['webhook_secret'] );
			}

			self::$config = $config;
		}

		return apply_filters( 'upserv_webhook_config', self::$config );
	}

	public static function get_instance() {

		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function schedule_webhook( $payload, $event_type, $instant = false ) {

		if ( empty( $this->webhooks ) ) {
			return;
		}

		if ( ! isset( $payload['event'], $payload['content'] ) ) {
			return new WP_Error(
				__METHOD__,
				__( 'The webhook payload must contain an event string and a content.', 'updatepulse-server' )
			);
		}

		$payload['origin']    = get_bloginfo( 'url' );
		$payload['timestamp'] = time();

		foreach ( $this->webhooks as $url => $info ) {
			$fire = false;

			if (
				isset( $info['secret'], $info['events'] ) &&
				! empty( $info['events'] ) &&
				is_array( $info['events'] )
			) {

				if ( in_array( $event_type, $info['events'], true ) ) {
					$fire = true;
				} else {

					foreach ( $info['events'] as $event ) {

						if ( $event === $payload['event'] && 0 === strpos( $event, $event_type ) ) {
							$fire = true;

							break;
						}
					}
				}
			}

			if ( apply_filters( 'upserv_webhook_fire', $fire, $payload, $url, $info ) ) {
				$body   = wp_json_encode(
					$payload,
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK
				);
				$hook   = 'upserv_webhook';
				$params = array( $url, $info['secret'], $body, current_action() );

				if ( ! as_has_scheduled_action( 'upserv_webhook', $params ) ) {
					$instant = apply_filters(
						'upserv_schedule_webhook_is_instant',
						$instant,
						$event_type,
						$params
					);

					if ( $instant ) {
						$this->fire_webhook( ...$params );

						continue;
					}

					$timestamp = time();

					as_schedule_single_action( $timestamp, $hook, $params );
				}
			}
		}
	}

	public function fire_webhook( $url, $secret, $body, $action ) {
		return wp_remote_post(
			$url,
			array(
				'method'   => 'POST',
				'blocking' => false,
				'headers'  => array(
					'X-UpdatePulse-Action'        => $action,
					'X-UpdatePulse-Signature-256' => 'sha256=' . hash_hmac( 'sha256', $body, $secret ),
				),
				'body'     => $body,
			)
		);
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected function handle_remote_test() {
		$sign       = $_SERVER['HTTP_X_UPDATEPULSE_SIGNATURE_256'];
		$sign_parts = explode( '=', $sign );
		$sign       = 2 === count( $sign_parts ) ? end( $sign_parts ) : false;
		$algo       = ( $sign ) ? reset( $sign_parts ) : false;
		$payload    = ( $sign ) ? filter_input_array(
			INPUT_POST,
			array(
				'test'   => FILTER_VALIDATE_INT,
				'source' => FILTER_SANITIZE_URL,
			)
		) : false;
		$valid      = false;

		if (
			$payload &&
			1 === intval( $payload['test'] ) &&
			! empty( $this->webhooks )
		) {
			$source   = $payload['source'];
			$webhooks = array_filter(
				$this->webhooks,
				function ( $key ) use ( $source ) {
					return 0 === strpos( $key, $source );
				},
				ARRAY_FILTER_USE_KEY
			);

			if ( ! empty( $webhooks ) ) {

				foreach ( $webhooks as $webhook ) {
					$secret = $webhook['secret'];
					$body   = wp_json_encode( $payload, JSON_NUMERIC_CHECK );
					$valid  = hash_equals( hash_hmac( $algo, $body, $secret ), $sign );

					if ( $valid ) {
						break;
					}
				}
			}
		}

		wp_send_json( $valid, $valid ? 200 : 403 );
	}

	protected function handle_api_request() {
		global $wp;

		if ( isset( $_SERVER['HTTP_X_UPDATEPULSE_SIGNATURE_256'] ) ) {
			$this->handle_remote_test();
		}

		$config   = self::get_config();
		$response = array();

		do_action( 'upserv_webhook_before_handling_request', $config );

		if ( $this->validate_request( $config ) ) {
			$package_id        = isset( $wp->query_vars['package_id'] ) ?
				trim( rawurldecode( $wp->query_vars['package_id'] ) ) :
				null;
			$type              = isset( $wp->query_vars['type'] ) ?
				trim( rawurldecode( $wp->query_vars['type'] ) ) :
				null;
			$delay             = $config['repository_check_delay'];
			$package_directory = Data_Manager::get_data_dir( 'packages' );
			$package_exists    = null;
			$payload           = @file_get_contents( 'php://input' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged

			if ( ! json_decode( $payload, true ) ) {
				parse_str( $payload, $payload );

				if ( is_array( $payload ) && isset( $payload['payload'] ) ) {
					$payload = json_decode( $payload['payload'] );
				} elseif ( is_string( $payload ) ) {
					$payload = json_decode( $payload );
				}

				$payload = $payload ? wp_json_encode( $payload ) : false;
			}

			$package_exists = apply_filters(
				'upserv_webhook_package_exists',
				$package_exists,
				$payload,
				$package_id,
				$type,
				$config
			);

			if ( null === $package_exists && is_dir( $package_directory ) ) {
				$package_path   = trailingslashit( $package_directory ) . $package_id . '.zip';
				$package_exists = file_exists( $package_path );
			}

			$process = apply_filters(
				'upserv_webhook_process_request',
				true,
				$payload,
				$package_id,
				$type,
				$package_exists,
				$config
			);

			if ( $process ) {
				do_action(
					'upserv_webhook_before_processing_request',
					$payload,
					$package_id,
					$type,
					$package_exists,
					$config
				);

				$hook = 'upserv_check_remote_' . $package_id;

				if ( $package_exists ) {
					$params           = array( $package_id, $type, true );
					$result           = true;
					$scheduled_action = as_next_scheduled_action( $hook, $params );
					$timestamp        = is_int( $scheduled_action ) ? $scheduled_action : false;

					if ( ! is_int( $scheduled_action ) ) {

						if ( ! $scheduled_action ) {
							as_unschedule_all_actions( $hook );
							do_action( 'upserv_cleared_check_remote_schedule', $package_id, $hook );
						}

						$delay     = apply_filters( 'upserv_check_remote_delay', $delay, $package_id );
						$timestamp = ( $delay ) ?
							time() + ( abs( intval( $delay ) ) * MINUTE_IN_SECONDS ) :
							time();
						$result    = as_schedule_single_action( $timestamp, $hook, $params );

						do_action(
							'upserv_scheduled_check_remote_event',
							$result,
							$package_id,
							$timestamp,
							false,
							$hook,
							$params
						);
					}

					if ( $result ) {
						$date = new DateTime( 'now', new DateTimeZone( wp_timezone_string() ) );

						$date->setTimestamp( $timestamp );

						$response['message'] = sprintf(
						/* translators: %1$s: package ID, %2$s: scheduled date and time */
							__( 'Package %1$s has been scheduled for download: %2$s.', 'updatepulse-server' ),
							sanitize_title( $package_id ),
							$date->format( 'Y-m-d H:i:s' ) . ' (' . wp_timezone_string() . ')'
						);
					} else {
						$this->http_response_code = 400;
						$response['message']      = sprintf(
						/* translators: %s: package ID */
							__( 'Failed to sechedule download for package %s.', 'updatepulse-server' ),
							sanitize_title( $package_id )
						);
					}
				} else {
					as_unschedule_all_actions( $hook );
					do_action( 'upserv_cleared_check_remote_schedule', $package_id, $hook );

					$result = upserv_download_remote_package( $package_id, $type );

					if ( $result ) {
						$response['message'] = sprintf(
						/* translators: %s: package ID */
							__( 'Package %s downloaded.', 'updatepulse-server' ),
							sanitize_title( $package_id )
						);
					} else {
						$this->http_response_code = 400;
						$response['message']      = sprintf(
						/* translators: %s: package ID */
							__( 'Failed to download package %s.', 'updatepulse-server' ),
							sanitize_title( $package_id )
						);
					}
				}

				do_action(
					'upserv_webhook_after_processing_request',
					$payload,
					$package_id,
					$type,
					$package_exists,
					$config
				);
			}

			$response['time_elapsed'] = sprintf( '%.3f', microtime( true ) - $_SERVER['REQUEST_TIME_FLOAT'] );
		} else {
			$this->http_response_code = 403;
			$response                 = array(
				'message' => __( 'Invalid request signature', 'updatepulse-server' ),
			);

			do_action( 'upserv_webhook_invalid_request', $config );
		}

		$response = apply_filters( 'upserv_webhook_response', $response, $this->http_response_code, $config );

		do_action( 'upserv_webhook_after_handling_request', $config, $response );
		wp_send_json( $response, $this->http_response_code );
	}

	protected function validate_request( $config ) {
		$valid  = false;
		$sign   = false;
		$secret = apply_filters( 'upserv_webhook_secret', $config['webhook_secret'], $config );

		if ( isset( $_SERVER['HTTP_X_GITLAB_TOKEN'] ) ) {
			$valid = $_SERVER['HTTP_X_GITLAB_TOKEN'] === $secret;
		} else {

			if ( isset( $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ) ) {
				$sign = $_SERVER['HTTP_X_HUB_SIGNATURE_256'];
			} elseif ( isset( $_SERVER['HTTP_X_HUB_SIGNATURE'] ) ) {
				$sign = $_SERVER['HTTP_X_HUB_SIGNATURE'];
			}

			$sign = apply_filters( 'upserv_webhook_signature', $sign, $secret, $config );

			if ( $sign ) {
				$sign_parts = explode( '=', $sign );
				$sign       = 2 === count( $sign_parts ) ? end( $sign_parts ) : false;
				$algo       = ( $sign ) ? reset( $sign_parts ) : false;
				$payload    = ( $sign ) ? @file_get_contents( 'php://input' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
				$valid      = $sign && hash_equals( hash_hmac( $algo, $payload, $secret ), $sign );
			}
		}

		return apply_filters( 'upserv_webhook_validate_request', $valid, $sign, $secret, $config );
	}
}
