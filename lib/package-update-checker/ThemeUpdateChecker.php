<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( ThemeUpdateChecker::class, false ) ) :

	/**
	 * Handles WordPress theme update checking functionality.
	 *
	 * This class extends the base UpdateChecker to provide theme-specific update checking, focusing on style.css parsing and theme header information retrieval.
	 */
	class ThemeUpdateChecker extends UpdateChecker {

		/** @var string The main theme file to check for updates */
		public $package_file = 'style.css';

		/**
		 * Initializes a new instance of the theme update checker.
		 *
		 * @param object $api       The Version Control System API instance
		 * @param string $slug      The theme's slug/directory name
		 * @param string $container The parent directory containing the theme
		 */
		public function __construct( $api, $slug, $container ) {
			$this->api                   = $api;
			$this->package_absolute_path = trailingslashit( $container ) . $slug;
			$this->debug_mode            = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name        = basename( dirname( $this->package_absolute_path ) );
			$this->slug                  = ! empty( $slug ) ? $slug : $this->directory_name;

			$this->api->set_slug( $this->slug );
		}

		/**
		 * Requests update information for the theme.
		 *
		 * @param string $type The type of package (defaults to 'Theme')
		 * @return array|WP_Error Update information array or WP_Error on failure
		 */
		public function request_info( $type = 'Theme' ) {
			$info = parent::request_info( $type );

			return $info;
		}

		/**
		 * Extracts version information from the theme's style.css file.
		 *
		 * @param string $file Content of the theme's style.css file
		 * @return string The version number found in the file or from update source
		 */
		protected function get_version_from_package_file( $file ) {
			$remote_header = $this->get_file_header( $file );

			return empty( $remote_header['Version'] ) ?
					$this->update_source->version :
					$remote_header['Version'];
		}

		/**
		 * Defines the standard WordPress theme header field names.
		 *
		 * @return array Associative array of theme header fields and their corresponding names
		 */
		protected function get_header_names() {
			return array(
				'Name'        => 'Theme Name',
				'ThemeURI'    => 'Theme URI',
				'Description' => 'Description',
				'Author'      => 'Author',
				'AuthorURI'   => 'Author URI',
				'Version'     => 'Version',
				'Template'    => 'Template',
				'Status'      => 'Status',
				'Tags'        => 'Tags',
				'TextDomain'  => 'Text Domain',
				'DomainPath'  => 'Domain Path',
			);
		}
	}

endif;
