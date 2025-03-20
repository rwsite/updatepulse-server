<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Cache class.
 *
 * @since 1.0.0
 */
class Cache {

	/**
	 * Cache directory path
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $cache_directory;

	/**
	 * File extension for cache files
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $extension;

	/**
	 * Constructor
	 *
	 * @param string $cache_directory Directory to store cache files.
	 * @param string $extension File extension for cache files. Default 'dat'.
	 * @since 1.0.0
	 */
	public function __construct( $cache_directory, $extension = 'dat' ) {
		$this->cache_directory = $cache_directory;
		$this->extension       = $extension;
	}

	/**
	 * Get cached value.
	 *
	 * Retrieves a value from the cache if it exists and hasn't expired.
	 *
	 * @param string $key Cache key identifier.
	 * @return mixed|null Cached value or null if not found or expired.
	 * @since 1.0.0
	 */
	public function get( $key ) {
		$filename = $this->get_cache_filename( $key );

		if ( is_file( $filename ) && is_readable( $filename ) ) {
			$cache = unserialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					file_get_contents( $filename ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				)
			);

			if ( $cache['expiration_time'] < time() ) {
				/* Could cause potential non-critical race condition */
				$this->clear( $key );

				return null; //Cache expired.
			} else {
				return $cache['value'];
			}
		}

		return null;
	}

	/**
	 * Update the cache.
	 *
	 * Stores a value in the cache with the specified expiration time.
	 *
	 * @param string $key Cache key identifier.
	 * @param mixed $value The value to store in the cache.
	 * @param int $expiration Time until expiration, in seconds. Optional. Default `0`.
	 * @return void
	 * @since 1.0.0
	 */
	public function set( $key, $value, $expiration = 0 ) {
		$cache = array(
			'expiration_time' => time() + $expiration,
			'value'           => $value,
		);

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->get_cache_filename( $key ),
			base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				serialize( $cache ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			)
		);
	}

	/**
	 * Clear the cache by key.
	 *
	 * Removes a specific cached value by its key.
	 *
	 * @param string $key Cache key identifier.
	 * @return void
	 * @since 1.0.0
	 */
	public function clear( $key ) {
		$file = $this->get_cache_filename( $key );

		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	/**
	 * Get cache filename
	 *
	 * Constructs the full path to a cache file based on its key.
	 *
	 * @param string $key Cache key identifier.
	 * @return string Full path to the cache file.
	 * @since 1.0.0
	 */
	protected function get_cache_filename( $key ) {
		return $this->cache_directory . '/' . $key . '.' . $this->extension;
	}
}
