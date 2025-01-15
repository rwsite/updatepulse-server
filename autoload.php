<?php

namespace Anyape\UpdatePulse\Server;

function autoload( $class_name ) {
	static $class_map = array(
		'Anyape\\UpdatePulse\\Server\\UPServ' => UPSERV_PLUGIN_PATH . 'inc/class-upserv.php',
		'Anyape\\Crypto\\Crypto'              => UPSERV_PLUGIN_PATH . 'lib/Crypto/crypto.php',
		'PhpS3\\PhpS3'                        => UPSERV_PLUGIN_PATH . 'lib/PhpS3/PhpS3.php',
	);

	$path = false;

	if ( isset( $class_map[ $class_name ] ) ) {
		$path = $class_map[ $class_name ];
	}

	if ( ! $path && 0 === strpos( $class_name, 'Anyape\\UpdatePulse\\Server\\' ) ) {
		$namespace_parts = explode( '\\', $class_name );
		$class_name      = array_pop( $namespace_parts );
		$folder_name     = strtolower( array_pop( $namespace_parts ) );
		$path            = UPSERV_PLUGIN_PATH . 'inc/'
			. $folder_name
			. '/class-'
			. str_replace( '_', '-', strtolower( $class_name ) )
			. '.php';
	}

	if ( $path && file_exists( $path ) ) {
		include $path;
	}
}

spl_autoload_register( 'Anyape\UpdatePulse\Server\autoload' );
