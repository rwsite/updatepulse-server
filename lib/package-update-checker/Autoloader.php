<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( Autoloader::class, false ) ) :

	class Autoloader {

		const DEFAULT_NS_PREFIX = 'Anyape\\PackageUpdateChecker\\';

		private $prefix;
		private $root_dir;

		public function __construct() {
			$this->root_dir       = __DIR__ . '/';
			$namespace_with_slash = __NAMESPACE__ . '\\';
			$this->prefix         = $namespace_with_slash;

			spl_autoload_register( array( $this, 'autoload' ) );
		}

		public function autoload( $class_name ) {

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

endif;
