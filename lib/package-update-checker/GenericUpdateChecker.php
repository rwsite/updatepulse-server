<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( GenericUpdateChecker::class, false ) ) :

	class GenericUpdateChecker extends UpdateChecker implements Vcs\BaseChecker {
		public $generic_absolute_path = '';
		public $generic_file          = '';

		public function __construct( $api, $slug, $file_name, $container ) {
			$this->api                   = $api;
			$this->generic_absolute_path = trailingslashit( $container ) . $slug;
			$this->generic_file          = $slug . '/' . $file_name . '.json';
			$this->debug_mode            = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name        = basename( dirname( $this->generic_absolute_path ) );
			$this->slug                  = ! empty( $slug ) ? $slug : $this->directory_name;

			$this->api->set_slug( $this->slug );
		}

		public function request_info() {
			$this->api->set_local_directory( trailingslashit( $this->generic_absolute_path ) );

			$update_source = $this->api->choose_reference( $this->branch );

			if ( $update_source ) {
				$ref = $update_source->name;
			} else {
				return new \WP_Error(
					'puc-no-update-source',
					'Could not retrieve version information from the repository for '
					. $this->slug . '.'
					. 'This usually means that the update checker either can\'t connect '
					. 'to the repository or it\'s configured incorrectly.'
				);
			}

			/**
			 * Pre-filter the info that will be returned by the update checker.
			 *
			 * @param array $info The info that will be returned by the update checker.
			 * @param YahnisElsts\PackageUpdateChecker\Vcs\API $api The API object that was used to fetch the info.
			 * @param string $ref The tag or branch that was used to fetch the info.
			 * @param YahnisElsts\PackageUpdateChecker\UpdateChecker $checker The update checker object calling this filter.
			 */
			$info = apply_filters(
				'puc_request_info_pre_filter',
				array( 'slug' => $this->slug ),
				$this->api,
				$ref,
				$this
			);

			if ( is_array( $info ) && isset( $info['abort_request'] ) && $info['abort_request'] ) {
				return $info;
			}

			$file = $this->api->get_remote_file( basename( $this->generic_file ), $ref );

			if ( ! empty( $file ) ) {
				$file_contents = json_decode( $file, true );

				if ( isset( $file_contents['packageData'] ) && ! empty( $file_contents['packageData'] ) ) {
					$remote_header   = $file_contents['packageData'];
					$info['version'] = empty( $remote_header['Version'] ) ? $update_source->version : $remote_header['Version'];
				}
			}

			$info['download_url'] = $this->api->sign_download_url( $update_source->download_url );
			$info['type']         = 'Generic';

			/**
			 * Filter the info that will be returned by the update checker.
			 *
			 * @param array $info The info that will be returned by the update checker.
			 * @param YahnisElsts\PackageUpdateChecker\Vcs\API $api The API object that was used to fetch the info.
			 * @param string $ref The tag or branch that was used to fetch the info.
			 * @param YahnisElsts\PackageUpdateChecker\UpdateChecker $checker The update checker object calling this filter.
			 */
			$info = apply_filters(
				'puc_request_info_result',
				$info,
				$this->api,
				$ref,
				$this
			);

			return $info;
		}

		protected function get_header_names() {
			return array();
		}

		public function set_branch( $branch ) {
			$this->branch = $branch;

			return $this;
		}

		public function set_authentication( $credentials ) {
			$this->api->set_authentication( $credentials );

			return $this;
		}

		public function get_vcs_api() {
			return $this->api;
		}
	}

endif;
