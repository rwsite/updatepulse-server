<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( UpdateChecker::class, false ) ) :

	abstract class UpdateChecker {

		public $debug_mode = null;
		public $directory_name;
		public $slug;
		public $package_absolute_path = '';

		protected $branch = 'main';
		protected $api;
		protected $ref;
		protected $update_source;
		protected $package_file;

		public function trigger_error( $message, $error_type ) {

			if ( $this->is_debug_mode_enabled() ) {
				//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Only happens in debug mode.
				trigger_error( esc_html( $message ), $error_type );
			}
		}

		public function get_file_header( $content ) {
			$content = (string) $content;

			//WordPress only looks at the first 8 KiB of the file, so we do the same.
			$content = substr( $content, 0, 8192 );
			//Normalize line endings.
			$content = str_replace( "\r", "\n", $content );

			$headers = $this->get_header_names();
			$results = array();

			foreach ( $headers as $field => $name ) {
				$success = preg_match( '/^[ \t\/*#@]*' . preg_quote( $name, '/' ) . ':(.*)$/mi', $content, $matches );

				if ( ( 1 === $success ) && $matches[1] ) {
					$value = $matches[1];

					if ( function_exists( '_cleanup_header_comment' ) ) {
						$value = _cleanup_header_comment( $value );
					}

					$results[ $field ] = $value;
				} else {
					$results[ $field ] = '';
				}
			}

			return $results;
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

		public function request_info( $type = 'Generic' ) {

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$this->api->set_local_directory( trailingslashit( $this->package_absolute_path ) );

			$update_source = $this->api->choose_reference( $this->branch );

			if ( $update_source ) {
				$ref = $update_source->name;
			} else {
				//There's probably a network problem or an authentication error.
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
			$info = is_array( $info ) ? $info : array();

			$this->ref           = $ref;
			$this->update_source = $update_source;

			if ( isset( $info['abort_request'] ) && $info['abort_request'] ) {
				return $info;
			}

			$file = $this->api->get_remote_file( basename( $this->package_file ), $this->ref );

			if ( ! empty( $file ) ) {
				$info['version'] = $this->get_version_from_package_file( $file );
			}

			$info['download_url'] = $this->update_source->download_url;
			$info['type']         = $type;
			$info['main_file']    = $this->package_file;

			/**
			 * Filter the info that will be returned by the update checker.
			 *
			 * @param array $info The info that will be returned by the update checker.
			 * @param YahnisElsts\PackageUpdateChecker\Vcs\API $api The API object that was used to fetch the info.
			 * @param string $ref The tag or branch that was used to fetch the info.
			 * @param YahnisElsts\PackageUpdateChecker\UpdateChecker $checker The update checker object calling this filter.
			 */
			$info = apply_filters( 'puc_request_info_result', $info, $this->api, $this->ref, $this );

			return $info;
		}

		abstract protected function get_version_from_package_file( $file );

		/**
		 * @return array Format: ['HeaderKey' => 'Header Name']
		 */
		abstract protected function get_header_names();

		/**
		 * @return bool
		 */
		protected function is_debug_mode_enabled() {

			if ( null === $this->debug_mode ) {
				$this->debug_mode = (bool) ( constant( 'WP_DEBUG' ) );
			}

			return $this->debug_mode;
		}
	}

endif;
