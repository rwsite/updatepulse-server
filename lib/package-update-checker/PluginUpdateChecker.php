<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( PluginUpdateChecker::class, false ) ) :

	class PluginUpdateChecker extends UpdateChecker {
		public $package_file = '';

		public function __construct( $api, $slug, $container, $file_name ) {
			$this->api                   = $api;
			$this->package_absolute_path = trailingslashit( $container ) . $slug;
			$this->package_file          = $slug . '/' . $file_name . '.php';
			$this->debug_mode            = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name        = basename( dirname( $this->package_absolute_path ) );
			$this->slug                  = $slug;

			$this->api->set_slug( $this->slug );
		}

		public function request_info( $type = 'Plugin' ) {
			$info = parent::request_info( $type );

			return $info;
		}

		protected function get_version_from_package_file( $file ) {
			$remote_header = $this->get_file_header( $file );

			return empty( $remote_header['Version'] ) ?
					$this->update_source->version :
					$remote_header['Version'];
		}

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

				//The newest WordPress version that this plugin requires or has been tested with.
				//We support several different formats for compatibility with other libraries.
				'Tested WP'         => 'Tested WP',
				'Requires WP'       => 'Requires WP',
				'Tested up to'      => 'Tested up to',
				'Requires at least' => 'Requires at least',
			);
		}
	}

endif;
