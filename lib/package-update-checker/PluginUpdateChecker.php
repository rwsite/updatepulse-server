<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( PluginUpdateChecker::class, false ) ) :

	/**
	 * Handles WordPress plugin update checking functionality.
	 *
	 * This class extends the base UpdateChecker to provide plugin-specific update checking, focusing on plugin header file parsing and metadata retrieval.
	 */
	class PluginUpdateChecker extends UpdateChecker {

		/** @var string Path to the main plugin file */
		public $package_file = '';

		/**
		 * Initializes a new instance of the plugin update checker.
		 *
		 * @param object $api        The Version Control System API instance
		 * @param string $slug       The plugin's slug/directory name
		 * @param string $container  The parent directory containing the plugin
		 * @param string $file_name  The main plugin file name without extension
		 */
		public function __construct( $api, $slug, $container, $file_name ) {
			$this->api                   = $api;
			$this->package_absolute_path = trailingslashit( $container ) . $slug;
			$this->package_file          = $slug . '/' . $file_name . '.php';
			$this->debug_mode            = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name        = basename( dirname( $this->package_absolute_path ) );
			$this->slug                  = $slug;

			$this->api->set_slug( $this->slug );
		}

		/**
		 * Requests update information for the plugin.
		 *
		 * @param string $type The type of package (defaults to 'Plugin')
		 * @return array|WP_Error Update information array or WP_Error on failure
		 */
		public function request_info( $type = 'Plugin' ) {
			$info = parent::request_info( $type );

			return $info;
		}

		/**
		 * Extracts version information from the plugin's main file.
		 *
		 * @param string $file Content of the plugin's main PHP file
		 * @return string The version number found in the file or from update source
		 */
		protected function get_version_from_package_file( $file ) {
			$remote_header = $this->get_file_header( $file );

			return empty( $remote_header['Version'] ) ?
					$this->update_source->version :
					$remote_header['Version'];
		}

		/**
		 * Defines the standard WordPress plugin header field names.
		 *
		 * These fields correspond to the metadata in the plugin's main PHP file
		 * that WordPress uses to identify and categorize plugins.
		 *
		 * @return array Associative array of plugin header fields and their corresponding names
		 */
		protected function get_header_names() {
			return array(
				'Name'              => 'Plugin Name',
				'PluginURI'         => 'Plugin URI',
				'Version'           => 'Version',
				'Description'       => 'Description',
				'Author'            => 'Author',
				'AuthorURI'         => 'Author URI',
				'TextDomain'        => 'Text Domain',
				'DomainPath'        => 'Domain Path',
				'Network'           => 'Network',
				'Tested WP'         => 'Tested WP',
				'Requires WP'       => 'Requires WP',
				'Tested up to'      => 'Tested up to',
				'Requires at least' => 'Requires at least',
			);
		}
	}

endif;
