<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( Autoloader::class, false ) ) :

	class Autoloader {
		const DEFAULT_NS_PREFIX = 'Anyape\\PackageUpdateChecker\\';

		private $prefix;
		private $root_dir;
		private $library_dir;

		private $static_map;

		public function __construct() {
			$this->root_dir       = __DIR__ . '/';
			$namespace_with_slash = __NAMESPACE__ . '\\';
			$this->prefix         = $namespace_with_slash;
			$this->library_dir    = realpath( $this->root_dir . '../..' ) . '/';
			//Usually, dependencies like Parsedown are in the global namespace,
			//but if someone adds a custom namespace to the entire library, they
			//will be in the same namespace as this class.
			$is_custom_namespace = (
				substr(
					$namespace_with_slash,
					0,
					strlen( self::DEFAULT_NS_PREFIX )
				) !== self::DEFAULT_NS_PREFIX
			);
			$library_prefix      = $is_custom_namespace ? $namespace_with_slash : '';
			$this->static_map    = array(
				$library_prefix . 'PucReadmeParser' => 'vendor/PucReadmeParser.php',
				$library_prefix . 'Parsedown'       => 'vendor/Parsedown.php',
			);

			spl_autoload_register( array( $this, 'autoload' ) );
		}

		public function autoload( $class_name ) {

			if (
				isset( $this->static_map[ $class_name ] ) &&
				file_exists( $this->library_dir . $this->static_map[ $class_name ] )
			) {
				include $this->library_dir . $this->static_map[ $class_name ];

				return;
			}

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
