<?php

namespace Anyape\UpdatePulse\Server;

function autoload( $class_name ) {
	static $class_map = array(
		'Anyape\\Crypto\\Crypto' => UPSERV_PLUGIN_PATH . 'inc/class-crypto.php',
		'PhpS3\\PhpS3'           => UPSERV_PLUGIN_PATH . 'lib/PhpS3/PhpS3.php',
	);

	$path = false;

	if ( 0 === \strpos( $class_name, __NAMESPACE__ . '\\UPServ' ) ) {
		$path = UPSERV_PLUGIN_PATH
			. 'inc/class-'
			. \strtolower(
				\str_replace(
					array( __NAMESPACE__ . '\\', '_' ),
					array( '', '-' ),
					$class_name
				)
			) .
			'.php';
	} elseif ( isset( $class_map[ $class_name ] ) ) {
		$path = $class_map[ $class_name ];
	}

	if ( $path && \file_exists( $path ) ) {
		include $path;
	}
}

\spl_autoload_register( 'Anyape\UpdatePulse\Server\autoload' );
