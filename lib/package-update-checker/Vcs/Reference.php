<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! class_exists( Reference::class, false ) ) :

	/**
	 * Class Reference
	 *
	 * This class represents a VCS branch or tag. It serves as a read-only, temporary container
	 * that provides a limited degree of type checking.
	 *
	 * @property string $name
	 * @property string|null $version
	 * @property string $download_url
	 * @property string $updated
	 * @property int|null $downloadCount
	 */
	class Reference {

		private $properties = array();

		/**
		 * Constructor.
		 *
		 * @param array $properties The properties to initialize the reference with.
		 */
		public function __construct( $properties = array() ) {
			$this->properties = $properties;
		}

		/**
		 * Magic getter method.
		 *
		 * @param string $name The property name.
		 * @return mixed|null The property value or null if it doesn't exist.
		 */
		public function __get( $name ) {
			return array_key_exists( $name, $this->properties ) ? $this->properties[ $name ] : null;
		}

		/**
		 * Magic setter method.
		 *
		 * @param string $name The property name.
		 * @param mixed $value The value to set.
		 */
		public function __set( $name, $value ) {
			$this->properties[ $name ] = $value;
		}

		/**
		 * Magic isset method.
		 *
		 * @param string $name The property name.
		 * @return bool True if the property is set, false otherwise.
		 */
		public function __isset( $name ) {
			return isset( $this->properties[ $name ] );
		}
	}

endif;
