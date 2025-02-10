<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! class_exists( BitbucketApi::class, false ) ) :

	class BitbucketApi extends Api {
		/**
		 * @var string Bitbucket app password. Optional.
		 */
		protected $app_password;

		public function __construct( $repository_url, $app_password = null ) {
			$path = wp_parse_url( $repository_url, PHP_URL_PATH );

			if ( preg_match( '@^/?(?P<user_name>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches ) ) {
				$this->user_name       = $matches['user_name'];
				$this->repository_name = $matches['repository'];
			} else {
				throw new \InvalidArgumentException(
					esc_html( 'Invalid Bitbucket repository URL: "' . $repository_url . '"' )
				);
			}

			parent::__construct( $repository_url, $app_password );
		}

		/**
		 * Check if the VCS is accessible.
		 *
		 * @return bool|\WP_Error
		 */
		public static function test( $url, $app_password = null ) {
			$instance = new self( $url . 'bogus/', $app_password );
			$endpoint = 'https://api.bitbucket.org/2.0/user';
			$response = $instance->api( $endpoint, '2.0', true );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			if ( $response && isset( $response->username ) && $instance->user_name === $response->username ) {
				return true;
			}

			if ( $response && isset( $response->code ) && 403 === $response->code ) {
				return 'missing_privileges';
			}

			return new \WP_Error(
				'puc-bitbucket-http-error',
				sprintf( 'Bitbucket API error. Base URL: "%s",  HTTP status code: %d.', $url, $response->code )
			);
		}

		protected function get_update_detection_strategies( $config_branch ) {
			$strategies[ self::STRATEGY_BRANCH ] = function () use ( $config_branch ) {
				return $this->get_branch( $config_branch );
			};

			if ( ( 'main' === $config_branch || 'master' === $config_branch ) ) {
				$strategies[ self::STRATEGY_LATEST_TAG ] = array( $this, 'get_latest_tag' );
			}

			return $strategies;
		}

		public function get_branch( $branch_name ) {
			$branch = $this->api( '/refs/branches/' . $branch_name );

			if ( is_wp_error( $branch ) || empty( $branch ) ) {
				return null;
			}

			//The "/src/{stuff}/{path}" endpoint doesn't seem to handle branch names that contain slashes.
			//If we don't encode the slash, we get a 404. If we encode it as "%2F", we get a 401.
			//To avoid issues, if the branch name is not URL-safe, let's use the commit hash instead.
			$ref = $branch->name;

			if ( ( rawurlencode( $ref ) !== $ref ) && isset( $branch->target->hash ) ) {
				$ref = $branch->target->hash;
			}

			return new Reference(
				array(
					'name'         => $ref,
					'updated'      => $branch->target->date,
					'download_url' => $this->get_download_url( $branch->name ),
				)
			);
		}

		/**
		 * Get a specific tag.
		 *
		 * @param string $tag_name
		 * @return Reference|null
		 */
		public function get_tag( $tag_name ) {
			$tag = $this->api( '/refs/tags/' . $tag_name );

			if ( is_wp_error( $tag ) || empty( $tag ) ) {
				return null;
			}

			return new Reference(
				array(
					'name'         => $tag->name,
					'version'      => ltrim( $tag->name, 'v' ),
					'updated'      => $tag->target->date,
					'download_url' => $this->get_download_url( $tag->name ),
				)
			);
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return Reference|null
		 */
		public function get_latest_tag() {
			$tags = $this->api( '/refs/tags?sort=-target.date' );

			if ( ! isset( $tags, $tags->values ) || ! is_array( $tags->values ) ) {
				return null;
			}

			//Filter and sort the list of tags.
			$version_tags = $this->sort_tags_by_version( $tags->values );

			//Return the first result.
			if ( ! empty( $version_tags ) ) {
				$tag = $version_tags[0];

				return new Reference(
					array(
						'name'         => $tag->name,
						'version'      => ltrim( $tag->name, 'v' ),
						'updated'      => $tag->target->date,
						'download_url' => $this->get_download_url( $tag->name ),
					)
				);
			}

			return null;
		}

		/**
		 * @param string $ref
		 * @return string
		 */
		protected function get_download_url( $ref ) {
			return sprintf(
				'https://bitbucket.org/%s/%s/get/%s.zip',
				$this->user_name,
				$this->repository_name,
				$ref
			);
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		public function get_remote_file( $path, $ref = 'main' ) {
			$response = $this->api( 'src/' . $ref . '/' . ltrim( $path ) );

			if ( is_wp_error( $response ) || ! is_string( $response ) ) {
				return null;
			}

			return $response;
		}

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name ( e.g. branch or tag ).
		 * @return string|null
		 */
		public function get_latest_commit_time( $ref ) {
			$response = $this->api( 'commits/' . $ref );

			if ( isset( $response->values, $response->values[0], $response->values[0]->date ) ) {
				return $response->values[0]->date;
			}

			return null;
		}

		/**
		 * Perform a Bitbucket API 2.0 request.
		 *
		 * @param string $url
		 * @param string $version
		 * @return mixed|\WP_Error
		 */
		public function api( $url, $version = '2.0', $override_url = false ) {
			$url             = ltrim( $url, '/' );
			$options         = array( 'timeout' => wp_doing_cron() ? 10 : 3 );
			$is_src_resource = 0 === strpos( $url, 'src/' );

			if ( ! $override_url ) {
				$url = implode(
					'/',
					array(
						'https://api.bitbucket.org',
						$version,
						'repositories',
						$this->user_name,
						$this->repository_name,
						$url,
					)
				);
			}

			if ( $this->is_authentication_enabled() ) {
				$options['headers'] = $this->get_authorization_headers();
			}

			$response = wp_remote_get( $url, $options );

			if ( is_wp_error( $response ) ) {
				do_action( 'puc_api_error', $response, null, $url, $this->slug );

				return $response;
			}

			$code = wp_remote_retrieve_response_code( $response );
			$body = wp_remote_retrieve_body( $response );

			if ( 200 === $code ) {

				if ( $is_src_resource ) {
					//Most responses are JSON-encoded, but src resources just
					//return raw file contents.
					$document = $body;
				} else {
					$document = json_decode( $body );
				}

				return $document;
			}

			if ( $override_url ) {
				$response       = json_decode( $body );
				$response->code = $code;

				return $response;
			}

			$error = new \WP_Error(
				'puc-bitbucket-http-error',
				sprintf( 'Bitbucket API error. Base URL: "%s",  HTTP status code: %d.', $url, $code )
			);

			do_action( 'puc_api_error', $error, $response, $url, $this->slug );

			return $error;
		}

		/**
		 * @param array $credentials
		 */
		public function set_authentication( $credentials ) {
			parent::set_authentication( $credentials );

			$this->app_password = is_string( $credentials ) ? $credentials : null;
		}

		/**
		 * Generate the value of the "Authorization" header.
		 *
		 * @return string
		 */
		public function get_authorization_headers() {
			return array(
				'Authorization' => 'Basic ' . base64_encode( $this->user_name . ':' . $this->app_password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);
		}
	}

endif;
