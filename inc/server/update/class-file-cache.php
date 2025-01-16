<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * A simple file-based cache.
 *
 * @internal Data is base64 encoded to avoid unserialization issues ( 'unserialize( ): Error at offset' ) which
 * could be caused by:
 * - inconsistent line endings
 * - unescaped quotes/slashes etc
 * - miscounted unicode characters
 *
 * @see https://github.com/YahnisElsts/wp-update-server/pull/11
 */
class File_Cache implements Cache {

	protected $cache_directory;

	public function __construct( $cache_directory ) {
		$this->cache_directory = $cache_directory;
	}

	/**
	 * Get cached value.
	 *
	 * @param string $key
	 * @return mixed|null
	 */
	public function get( $key ) {
		$filename = $this->getCacheFilename( $key );

		if ( is_file( $filename ) && is_readable( $filename ) ) {
			$cache = unserialize( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
				base64_decode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					file_get_contents( $filename ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				)
			);

			if ( $cache['expiration_time'] < time() ) {
				/* Could cause potential non-critical race condition
				@see https://github.com/YahnisElsts/wp-update-server/pull/22 */
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
	 * @param string $key Cache key.
	 * @param mixed $value The value to store in the cache.
	 * @param int $expiration Time until expiration, in seconds. Optional.
	 * @return void
	 */
	public function set( $key, $value, $expiration = 0 ) {
		$cache = array(
			'expiration_time' => time() + $expiration,
			'value'           => $value,
		);

		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->getCacheFilename( $key ),
			base64_encode( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				serialize( $cache ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			)
		);
	}

	/**
	 * @param string $key
	 * @return string
	 */
	protected function getCacheFilename( $key ) {
		return $this->cache_directory . '/' . $key . '.txt';
	}

	/**
	 * Clear a cache.
	 *
	 * @param string $key Cache key.
	 * @return void
	 */
	public function clear( $key ) {
		$file = $this->getCacheFilename( $key );

		if ( is_file( $file ) ) {
			unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}
}
