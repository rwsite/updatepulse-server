<?php

namespace Anyape\UpdatePulse\Server\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use WP_Error;

class Scheduler {
	protected static $instance = null;

	public function __construct( $init_hooks = false ) {

		if ( $init_hooks ) {
			add_action( 'action_scheduler_init', array( $this, 'action_scheduler_init' ), 10, 0 );
			add_action( 'init', array( $this, 'init' ), 5, 0 );
		}
	}

	public static function get_instance() {

		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __call( $name, $arguments ) {

		if ( ! method_exists( $this, $name ) ) {
			return new WP_Error(
				'invalid_method',
				sprintf(
					/* translators: 1: full cass name ; 2: method name */
					__( '%1$s: Method %2$s does not exist.', 'updatepulse-server' ),
					esc_html( __CLASS__ ),
					esc_html( $name )
				)
			);
		}

		if ( class_exists( 'ActionScheduler', false ) ) {
			$name = 'as_' . $name;

			return $name( ...$arguments );
		}

		return $this->$name( ...$arguments );
	}

	public function action_scheduler_init() {
		do_action( 'upserv_scheduler_init' );
	}

	public function init() {

		if ( ! class_exists( 'ActionScheduler', false ) ) {
			do_action( 'upserv_scheduler_init' );
		}
	}

	protected function schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {  // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( $unique ) {
			$this->unschedule_all_actions( $hook, $args, $group );
		}

		return wp_schedule_single_event( $timestamp, $hook, $args );
	}

	protected function schedule_recurring_action( $timestamp, $interval_in_seconds, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		if ( $unique ) {
			$this->unschedule_all_actions( $hook, $args, $group );
		}

		$schedules        = wp_get_schedules();
		$intervals        = array_map(
			function ( $schedule ) {
				return $schedule['interval'];
			},
			$schedules
		);
		$closest_interval = null;
		$smallest_diff    = PHP_INT_MAX;

		foreach ( $intervals as $interval_name => $interval_value ) {
			$diff = abs( $interval_value - $interval_in_seconds );

			if ( $diff < $smallest_diff ) {
				$smallest_diff    = $diff;
				$closest_interval = $interval_name;
			}
		}

		$interval = $closest_interval ? $closest_interval : 'hourly';

		return wp_schedule_event( $timestamp, $interval, $hook, $args );
	}

	protected function unschedule_all_actions( $hook, $args = array(), $group = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$timestamp = wp_next_scheduled( $hook, $args );

		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook, $args );
			$timestamp = wp_next_scheduled( $hook, $args );
		}
	}

	protected function next_scheduled_action( $hook, $args = array(), $group = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return wp_next_scheduled( $hook, $args );
	}

	protected function has_scheduled_action( $hook, $args = array(), $group = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return (bool) wp_next_scheduled( $hook, $args );
	}
}
