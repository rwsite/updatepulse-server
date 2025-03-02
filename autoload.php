<?php
/**
 * Custom PSR-4 Autoloader for UpdatePulse Server
 *
 * Handles class autoloading for the plugin, mapping namespaces to file paths
 * using both explicit class mappings and PSR-4 style directory structure.
 *
 * @package UPServ
 * @since 1.0.0
 */

namespace Anyape\UpdatePulse\Server;

/**
 * Autoload function for loading plugin classes
 *
 * This function is responsible for automatically loading classes based on their namespace.
 * It uses a static class map for direct class-to-file mappings, and falls back to
 * PSR-4 style path generation for plugin-specific namespaces.
 *
 * @since 1.0.0
 * @param string $class_name The fully qualified class name to load
 * @return void
 */
function autoload( $class_name ) {
	static $f_root    = UPSERV_PLUGIN_PATH;
	static $ns_root   = __NAMESPACE__;
	static $class_map = array();

	$path      = false;
	$class_map = empty( $class_map ) ? array(
		$ns_root                                      => $f_root . 'inc/class-upserv.php',
		'Anyape\\Utils\\Utils'                        => $f_root . 'inc/class-utils.php',
		'Anyape\\Crypto\\Crypto'                      => $f_root . 'lib/anyape-crypto/crypto.php',
		'Anyape\\Parsedown'                           => $f_root . 'lib/parsedown/Parsedown.php',
		'Anyape\\UpdatePulse\\Package_Parser\\Parser' => $f_root . 'lib/anyape-package-parser/package-parser.php',
		'PhpS3\\PhpS3'                                => $f_root . 'lib/PhpS3/PhpS3.php',
	) : $class_map;

	if ( isset( $class_map[ $class_name ] ) && ! class_exists( $class_name, false ) ) {
		$path = $class_map[ $class_name ];
	}

	if ( ! $path && 0 === strpos( $class_name, $ns_root ) ) {
		$ns_frags   = explode( '\\', str_replace( $ns_root, '', $class_name ) );
		$class_frag = str_replace( '_', '-', strtolower( array_pop( $ns_frags ) ) );
		$path_frags = str_replace(
			'_',
			'-',
			strtolower( implode( '/', $ns_frags ) )
		);
		$path       = $f_root . 'inc/' . $path_frags . '/class-' . $class_frag . '.php';
	}

	if ( $path && file_exists( $path ) && is_readable( $path ) ) {
		include $path;
	}
}

// Register the autoloader with SPL
spl_autoload_register( 'Anyape\UpdatePulse\Server\autoload' );
