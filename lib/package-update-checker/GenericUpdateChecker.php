<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( GenericUpdateChecker::class, false ) ) :

	/**
	 * Generic update checker for non-WordPress packages.
	 *
	 * This class extends the base UpdateChecker to provide update checking functionality for generic packages that use JSON files for metadata storage.
	 */
	class GenericUpdateChecker extends UpdateChecker {

		/**
		 * Initializes a new instance of the generic update checker.
		 *
		 * @param object $api        The Version Control System API instance
		 * @param string $slug       The package's slug/directory name
		 * @param string $container  The parent directory containing the package
		 * @param string $file_name  The main package file name without extension
		 */
		public function __construct( $api, $slug, $container, $file_name ) {
			$this->api                   = $api;
			$this->package_absolute_path = trailingslashit( $container ) . $slug;
			$this->package_file          = $slug . '/' . $file_name . '.json';
			$this->debug_mode            = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name        = basename( dirname( $this->package_absolute_path ) );
			$this->slug                  = ! empty( $slug ) ? $slug : $this->directory_name;

			$this->api->set_slug( $this->slug );
		}

		/**
		 * Extracts version information from the package's JSON file.
		 *
		 * Parses the JSON file content and looks for version information in the
		 * packageData section of the JSON structure.
		 *
		 * @param string $file Content of the package's JSON file
		 * @return string The version number found in the file or from update source
		 */
		protected function get_version_from_package_file( $file ) {
			$file_contents = json_decode( $file, true );

			if ( isset( $file_contents['packageData'] ) && ! empty( $file_contents['packageData'] ) ) {
				$remote_header = $file_contents['packageData'];

				return empty( $remote_header['Version'] ) ?
					$this->update_source->version :
					$remote_header['Version'];
			}

			return $this->update_source->version;
		}

		/**
		 * Returns an empty array since generic packages don't use header names.
		 *
		 * This method is implemented to satisfy the abstract parent class requirement
		 * but returns an empty array as generic packages use JSON format instead of
		 * header fields.
		 *
		 * @return array Empty array as generic packages don't use header fields
		 */
		protected function get_header_names() {
			return array();
		}
	}

endif;
