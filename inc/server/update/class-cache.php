<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

/**
 * A very basic cache interface.
 */
interface Cache {
	/**
	 * Get cached value.
	 *
	 * @param string $key
	 * @return mixed|null
	 */
	public function get( $key );

	/**
	 * Update the cache.
	 *
	 * @param string $key Cache key.
	 * @param mixed $value The value to store in the cache.
	 * @param int $expiration Time until expiration, in seconds. Optional.
	 * @return void
	 */
	public function set( $key, $value, $expiration = 0 );

	/**
	 * Clear a cache
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function clear( $key );
}
