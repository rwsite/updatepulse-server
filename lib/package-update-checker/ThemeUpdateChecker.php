<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( ThemeUpdateChecker::class, false ) ) :

	class ThemeUpdateChecker extends UpdateChecker {
		public $package_file = 'style.css';

		public function __construct( $api, $slug, $container ) {
			$this->api                   = $api;
			$this->package_absolute_path = trailingslashit( $container ) . $slug;
			$this->debug_mode            = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name        = basename( dirname( $this->package_absolute_path ) );
			$this->slug                  = ! empty( $slug ) ? $slug : $this->directory_name;

			$this->api->set_slug( $this->slug );
		}

		public function request_info( $type = 'Theme' ) {
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
