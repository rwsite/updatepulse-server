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
	// generate static methods based on above functions

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
}
