<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( UpdateChecker::class, false ) ) :

	/**
	 * Abstract base class for package update checking functionality.
	 *
	 * Provides core functionality for checking updates from various version control systems.
	 */
	abstract class UpdateChecker {

		/** @var bool|null Debug mode status */
		public $debug_mode = null;

		/** @var string Directory name of the package */
		public $directory_name;

		/** @var string Unique identifier for the package */
		public $slug;

		/** @var string Absolute path to the package directory */
		public $package_absolute_path = '';

		/** @var string Branch name to check for updates, defaults to 'main' */
		protected $branch = 'main';

		/** @var object Version Control System API instance */
		protected $api;

		/** @var string Current reference (tag/branch) being checked */
		protected $ref;

		/** @var object Source of the update */
		protected $update_source;

		/** @var string Path to the main package file */
		protected $package_file;

		/**
		 * Triggers an error message when in debug mode.
		 *
		 * @param string $message    The error message to display
		 * @param int    $error_type The type of error to trigger
		 * @return void
		 */
		public function trigger_error( $message, $error_type ) {

			if ( $this->is_debug_mode_enabled() ) {
				//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Only happens in debug mode.
				trigger_error( esc_html( $message ), $error_type );
			}
		}

		/**
		 * Extracts header information from a file's content.
		 *
		 * @param string $content The content to parse for headers
		 * @return array Associative array of header fields and their values
		 */
		public function get_file_header( $content ) {
			$content = (string) $content;
			$content = substr( $content, 0, 8192 ); // Limit to 8KB
			$content = str_replace( "\r", "\n", $content ); // Normalize line endings
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

		/**
		 * Sets the branch to check for updates.
		 *
		 * @param string $branch The branch name
		 * @return self
		 */
		public function set_branch( $branch ) {
			$this->branch = $branch;

			return $this;
		}

		/**
		 * Sets authentication credentials for the API.
		 *
		 * @param mixed $credentials The authentication credentials
		 * @return self
		 */
		public function set_authentication( $credentials ) {
			$this->api->set_authentication( $credentials );

			return $this;
		}

		/**
		 * Gets the VCS API instance.
		 *
		 * @return object The VCS API instance
		 */
		public function get_vcs_api() {
			return $this->api;
		}

		/**
		 * Requests update information from the repository.
		 *
		 * @param string $type The type of package (default: 'Generic')
		 * @return array|WP_Error Update information or error on failure
		 */
		public function request_info( $type = 'Generic' ) {

			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$this->api->set_local_directory( trailingslashit( $this->package_absolute_path ) );

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

		/**
		 * Extracts version information from the package file.
		 *
		 * @param string $file Content of the package file
		 * @return string Version number
		 */
		abstract protected function get_version_from_package_file( $file );

		/**
		 * Gets the header field names to look for in the package file.
		 *
		 * @return array Associative array of header keys and their names
		 */
		abstract protected function get_header_names();

		/**
		 * Checks if debug mode is enabled.
		 *
		 * @return bool True if debug mode is enabled, false otherwise
		 */
		protected function is_debug_mode_enabled() {

			if ( null === $this->debug_mode ) {
				$this->debug_mode = (bool) ( constant( 'WP_DEBUG' ) );
			}

			return $this->debug_mode;
		}
	}

endif;
