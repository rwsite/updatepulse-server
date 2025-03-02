<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( Autoloader::class, false ) ) :

	/**
	 * Handles class autoloading for the Package Update Checker library.
	 *
	 * Automatically loads class files based on PSR-4 naming conventions when they are referenced in the code.
	 */
	class Autoloader {

		/** @var string The default namespace prefix for the library */
		const DEFAULT_NS_PREFIX = 'Anyape\\PackageUpdateChecker\\';

		/** @var string The namespace prefix to handle */
		private $prefix;

		/** @var string The root directory containing class files */
		private $root_dir;

		/**
		 * Initializes the autoloader and registers it with SPL.
		 *
		 * Sets up the root directory and namespace prefix, then registers the autoload method with PHP's SPL autoloader.
		 */
		public function __construct() {
			$this->root_dir       = __DIR__ . '/';
			$namespace_with_slash = __NAMESPACE__ . '\\';
			$this->prefix         = $namespace_with_slash;

			spl_autoload_register( array( $this, 'autoload' ) );
		}

		/**
		 * Attempts to load a class file based on its fully qualified name.
		 *
		 * Converts the namespace and class name into a file path and includes the file if it exists.
		 *
		 * @param string $class_name The fully qualified class name to load
		 * @return void
		 */
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
