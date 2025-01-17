<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( UpdateChecker::class, false ) ) :

	abstract class UpdateChecker {
		public $debug_mode = null;
		public $directory_name;
		public $slug;

		protected $branch = 'main';
		protected $api;

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
