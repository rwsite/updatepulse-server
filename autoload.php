<?php

namespace Anyape\UpdatePulse\Server;

function autoload( $class_name ) {
	static $f_root    = UPSERV_PLUGIN_PATH;
	static $ns_root   = __NAMESPACE__;
	static $class_map = array();

	$path      = false;
	$class_map = empty( $class_map ) ? array(
		$ns_root                 => $f_root . 'inc/class-upserv.php',
		'Anyape\\Crypto\\Crypto' => $f_root . 'lib/anyape-crypto/crypto.php',
		'PhpS3\\PhpS3'           => $f_root . 'lib/PhpS3/PhpS3.php',
		'\\PclZip'               => $f_root . 'lib/PclZip/pclzip.php',
	) : $class_map;

	if ( isset( $class_map[ $class_name ] ) ) {
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

	if ( ! $path && 0 === strpos( $class_name, 'Anyape\\UpdatePulse\\Package_Parser' ) ) {
		$ns_frags   = explode( '\\', str_replace( 'Anyape\\UpdatePulse\\Package_Parser', '', $class_name ) );
		$class_frag = str_replace( '_', '-', strtolower( array_pop( $ns_frags ) ) );
		$path       = $f_root . 'lib/anyape-package-parser/package-' . $class_frag . '.php';
	}

	if ( $path && file_exists( $path ) && is_readable( $path ) ) {
		include $path;
	}
}

spl_autoload_register( 'Anyape\UpdatePulse\Server\autoload' );
