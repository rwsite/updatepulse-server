<?php

namespace Anyape\ProxyUpdateChecker;

if ( ! class_exists( Autoloader::class, false ) ) {

	class Autoloader {
		private $prefix   = '';
		private $root_dir = '';

		public function __construct() {
			$this->root_dir = __DIR__ . '/';
			$this->prefix   = __NAMESPACE__ . '\\';

			spl_autoload_register( array( $this, 'autoload' ) );
		}

		public function autoload( $class_name ) {
			$path = substr( $class_name, strlen( $this->prefix ) );
			$path = str_replace( array( '_', '\\' ), '/', $path );

			if ( 0 === strpos( $class_name, $this->prefix ) ) {
				$path = substr( $class_name, strlen( $this->prefix ) );
				$path = str_replace( array( '_', '\\' ), '/', $path );
				$path = $this->root_dir . $path . '.php';

				if ( file_exists( $path ) ) {
					include $path;
				}
			}
		}
	}
}
