<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( GenericUpdateChecker::class, false ) ) :

	class GenericUpdateChecker extends UpdateChecker {

		public function __construct( $api, $slug, $container, $file_name ) {
			$this->api                   = $api;
			$this->package_absolute_path = trailingslashit( $container ) . $slug;
			$this->package_file          = $slug . '/' . $file_name . '.json';
			$this->debug_mode            = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name        = basename( dirname( $this->package_absolute_path ) );
			$this->slug                  = ! empty( $slug ) ? $slug : $this->directory_name;

			$this->api->set_slug( $this->slug );
		}

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

		protected function get_header_names() {
			return array();
		}
	}

endif;
