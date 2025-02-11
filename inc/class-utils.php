<?php

namespace Anyape\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class Utils
 *
 * @package Anyape\Utils
 */
class Utils {

	// JSON options
	const JSON_OPTIONS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK;

	/**
	 * @param string $message
	 * @param string $prefix
	 */
	public static function php_log( $message = '', $prefix = '' ) {
		$prefix   = $prefix ? ' ' . $prefix . ' => ' : ' => ';
		$trace    = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$caller   = end( $trace );
		$class    = isset( $caller['class'] ) ? $caller['class'] : '';
		$type     = isset( $caller['type'] ) ? $caller['type'] : '';
		$function = isset( $caller['function'] ) ? $caller['function'] : '';
		$context  = $class . $type . $function . $prefix;

		error_log( $context . print_r( $message, true ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * @param string $ip
	 * @param string $range
	 *
	 * @return bool
	 */
	public static function cidr_match( $ip, $range ) {
		list ( $subnet, $bits ) = explode( '/', $range );
		$ip                     = ip2long( $ip );
		$subnet                 = ip2long( $subnet );

		if ( ! $ip || ! $subnet || ! $bits ) {
			return false;
		}

		$mask    = -1 << ( 32 - $bits );
		$subnet &= $mask; // in case the supplied subnet was not correctly aligned

		return ( $ip & $mask ) === $subnet;
	}

	/**
	 * @param array  $_array
	 * @param string $path
	 * @param null   $value
	 * @param bool   $update
	 *
	 * @return mixed|null
	 */
	public static function access_nested_array( &$_array, $path, $value = null, $update = false ) {
		$keys    = explode( '/', $path );
		$current = &$_array;

		foreach ( $keys as $key ) {

			if ( ! isset( $current[ $key ] ) ) {

				if ( $update ) {
					$current[ $key ] = array();
				} else {
					return null;
				}
			}

			$current = &$current[ $key ];
		}

		if ( $update ) {
			$current = $value;
		}

		return $current;
	}

	/**
	 * @param string $path
	 * @param string $regex
	 *
	 * @return int|null
	 */
	public static function is_url_subpath_match( $regex ) {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : false;

		if ( ! $host && isset( $_SERVER['SERVER_NAME'] ) ) {
			$host = sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) );
		}

		if ( ! $host || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}

		$url   = sanitize_url( 'https://' . $host . wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path  = str_replace( trailingslashit( home_url() ), '', $url );
		$frags = explode( '/', $path );

		return preg_match( $regex, $frags[0] );
	}

	/**
	 * @param string $path
	 * @param string $regex
	 *
	 * @return int|null
	 */
	public static function get_time_elapsed() {

		if ( empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			return null;
		}

		$req_time_float = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_TIME_FLOAT'] ) );

		if ( ! is_numeric( $req_time_float ) ) {
			return null;
		}

		return sprintf( '%.3f', microtime( true ) - $req_time_float );
	}

	/**
	 * @param string $path
	 * @param string $regex
	 *
	 * @return int|null
	 */
	public static function get_remote_ip() {

		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '0.0.0.0';
		}

		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}

		return $ip;
	}
}
