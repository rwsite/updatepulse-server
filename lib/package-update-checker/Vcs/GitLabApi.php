<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! class_exists( GitLabApi::class, false ) ) :

	class GitLabApi extends Api {
		use ReleaseAssetSupport;
		use ReleaseFilteringFeature;

		/**
		 * @var string GitLab server host.
		 */
		protected $repository_host;

		/**
		 * @var string Protocol used by this GitLab server: "http" or "https".
		 */
		protected $repository_protocol = 'https';

		/**
		 * @var string GitLab authentication token. Optional.
		 */
		protected $access_token;

		public function __construct( $repository_url, $access_token = null, $sub_group = null ) {
			//Parse the repository host to support custom hosts.
			$port = wp_parse_url( $repository_url, PHP_URL_PORT );

			if ( ! empty( $port ) ) {
				$port = ':' . $port;
			}

			$this->repository_host = wp_parse_url( $repository_url, PHP_URL_HOST ) . $port;

			if ( 'gitlab.com' !== $this->repository_host ) {
				$this->repository_protocol = wp_parse_url( $repository_url, PHP_URL_SCHEME );
			}

			//Find the repository information
			$path = wp_parse_url( $repository_url, PHP_URL_PATH );

			if ( preg_match( '@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches ) ) {
				$this->user_name       = $matches['username'];
				$this->repository_name = $matches['repository'];
			} elseif ( ( 'gitlab.com' === $this->repository_host ) ) {
				//This is probably a repository in a sub_group, e.g. "/organization/category/repo".
				$parts = explode( '/', trim( $path, '/' ) );

				if ( count( $parts ) < 3 ) {
					throw new \InvalidArgumentException(
						esc_html(
							'Invalid GitLab.com repository URL: "' . $repository_url . '"'
						)
					);
				}

				$last_part             = array_pop( $parts );
				$this->user_name       = implode( '/', $parts );
				$this->repository_name = $last_part;
			} else {

				//There could be sub_groups in the URL:  gitlab.domain.com/group/sub_group/sub_group2/repository
				if ( null === $sub_group ) {
					$path = str_replace( trailingslashit( $sub_group ), '', $path );
				}

				//This is not a traditional url, it could be gitlab is in a deeper subdirectory.
				//Get the path segments.
				$segments = explode( '/', untrailingslashit( ltrim( $path, '/' ) ) );

				//We need at least /user-name/repository-name/
				if ( count( $segments ) < 2 ) {
					throw new \InvalidArgumentException(
						esc_html(
							'Invalid GitLab repository URL: "' . $repository_url . '"'
						)
					);
				}

				//Get the username and repository name.
				$username_repo         = array_splice( $segments, -2, 2 );
				$this->user_name       = $username_repo[0];
				$this->repository_name = $username_repo[1];

				//Append the remaining segments to the host if there are segments left.
				if ( count( $segments ) > 0 ) {
					$this->repository_host = trailingslashit( $this->repository_host ) . implode( '/', $segments );
				}

				//Add sub_groups to username.
				if ( null !== $sub_group ) {
					$this->user_name = $username_repo[0] . '/' . untrailingslashit( $sub_group );
				}
			}

			parent::__construct( $repository_url, $access_token );
		}

		/**
		 * Check if the VCS is accessible.
		 *
		 * @return bool|\WP_Error
		 */
		public static function test( $url, $access_token = null ) {
			$instance = new self( $url . 'bogus/', $access_token );
			$endpoint = sprintf(
				'%1$s://%2$s/api/v4/groups/%3$s',
				$instance->repository_protocol,
				$instance->repository_host,
				rawurlencode( $instance->user_name )
			);
			$response = $instance->api( $endpoint, array(), true );

			php_log( $response );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return $response && isset( $response->path ) && $instance->user_name === $response->path;
		}

		/**
		 * Get the latest release from GitLab.
		 *
		 * @return Reference|null
		 */
		public function get_latest_release() {
			$releases = $this->api( '/:id/releases', array( 'per_page' => $this->release_filter_max_releases ) );

			if ( is_wp_error( $releases ) || empty( $releases ) || ! is_array( $releases ) ) {
				return null;
			}

			foreach ( $releases as $release ) {

				if (
					//Skip invalid/unsupported releases.
					! is_object( $release )
					|| ! isset( $release->tag_name )
					//Skip upcoming releases.
					|| (
						! empty( $release->upcoming_release )
						&& $this->should_skip_pre_releases()
					)
				) {
					continue;
				}

				$version_number = ltrim( $release->tag_name, 'v' ); //Remove the "v" prefix from "v1.2.3".

				//Apply custom filters.
				if ( ! $this->matches_custom_release_filter( $version_number, $release ) ) {
					continue;
				}

				$download_url = $this->find_release_download_url( $release );
				if ( empty( $download_url ) ) {
					//The latest release doesn't have valid download URL.
					return null;
				}

				return new Reference(
					array(
						'name'         => $release->tag_name,
						'version'      => $version_number,
						'download_url' => $download_url,
						'updated'      => $release->released_at,
						'apiResponse'  => $release,
					)
				);
			}

			return null;
		}

		/**
		 * @param object $release
		 * @return string|null
		 */
		protected function find_release_download_url( $release ) {

			if ( $this->release_assets_enabled ) {

				if ( isset( $release->assets, $release->assets->links ) ) {

					//Use the first asset link where the URL matches the filter.
					foreach ( $release->assets->links as $link ) {

						if ( $this->matches_asset_filter( $link ) ) {
							return $link->url;
						}
					}
				}

				if ( Api::REQUIRE_RELEASE_ASSETS === $this->release_asset_preference ) {
					//Falling back to source archives is not allowed, so give up.
					return null;
				}
			}

			//Use the first source code archive that's in ZIP format.
			foreach ( $release->assets->sources as $source ) {

				if ( isset( $source->format ) && ( 'zip' === $source->format ) ) {
					return $source->url;
				}
			}

			return null;
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return Reference|null
		 */
		public function get_latest_tag() {
			$tags = $this->api( '/:id/repository/tags' );

			if ( is_wp_error( $tags ) || empty( $tags ) || ! is_array( $tags ) ) {
				return null;
			}

			$version_tags = $this->sort_tags_by_version( $tags );

			if ( empty( $version_tags ) ) {
				return null;
			}

			$tag = $version_tags[0];

			return new Reference(
				array(
					'name'         => $tag->name,
					'version'      => ltrim( $tag->name, 'v' ),
					'download_url' => $this->build_archive_download_url( $tag->name ),
					'apiResponse'  => $tag,
				)
			);
		}

		/**
		 * Get a branch by name.
		 *
		 * @param string $branch_name
		 * @return null|Reference
		 */
		public function get_branch( $branch_name ) {
			$branch = $this->api( '/:id/repository/branches/' . $branch_name );

			if ( is_wp_error( $branch ) || empty( $branch ) ) {
				return null;
			}

			$reference = new Reference(
				array(
					'name'         => $branch->name,
					'download_url' => $this->build_archive_download_url( $branch->name ),
					'apiResponse'  => $branch,
				)
			);

			if ( isset( $branch->commit, $branch->commit->committed_date ) ) {
				$reference->updated = $branch->commit->committed_date;
			}

			return $reference;
		}

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name ( e.g. branch or tag ).
		 * @return string|null
		 */
		public function get_latest_commit_time( $ref ) {
			$commits = $this->api( '/:id/repository/commits/', array( 'ref_name' => $ref ) );

			if ( is_wp_error( $commits ) || ! is_array( $commits ) || ! isset( $commits[0] ) ) {
				return null;
			}

			return $commits[0]->committed_date;
		}

		/**
		 * Perform a GitLab API request.
		 *
		 * @param string $url
		 * @param array $query_params
		 * @return mixed|\WP_Error
		 */
		protected function api( $url, $query_params = array(), $override_url = false ) {

			if ( ! $override_url ) {
				$url = $this->build_api_url( $url, $query_params );
			}

			$options = array( 'timeout' => wp_doing_cron() ? 10 : 3 );

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
				return json_decode( $body );
			}

			if ( $override_url ) {
				$response       = json_decode( $body );
				$response->code = $code;

				return $response;
			}

			$error = new \WP_Error(
				'puc-gitlab-http-error',
				sprintf( 'GitLab API error. URL: "%s",  HTTP status code: %d.', $url, $code )
			);

			do_action( 'puc_api_error', $error, $response, $url, $this->slug );

			return $error;
		}

		/**
		 * Build a fully qualified URL for an API request.
		 *
		 * @param string $url
		 * @param array $query_params
		 * @return string
		 */
		protected function build_api_url( $url, $query_params ) {
			$variables = array(
				'user' => $this->user_name,
				'repo' => $this->repository_name,
				'id'   => $this->user_name . '/' . $this->repository_name,
			);

			foreach ( $variables as $name => $value ) {
				$url = str_replace( "/:{$name}", '/' . rawurlencode( $value ), $url );
			}

			$url = substr( $url, 1 );
			$url = sprintf( '%1$s://%2$s/api/v4/projects/%3$s', $this->repository_protocol, $this->repository_host, $url );

			if ( ! empty( $query_params ) ) {
				$url = add_query_arg( $query_params, $url );
			}

			return $url;
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		public function get_remote_file( $path, $ref = 'main' ) {
			$response = $this->api( '/:id/repository/files/' . $path, array( 'ref' => $ref ) );

			if ( is_wp_error( $response ) || ! isset( $response->content ) || 'base64' !== $response->encoding ) {
				return null;
			}

			return base64_decode( $response->content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}

		/**
		 * Generate a URL to download a ZIP archive of the specified branch/tag/etc.
		 *
		 * @param string $ref
		 * @return string
		 */
		public function build_archive_download_url( $ref = 'main' ) {
			$url = sprintf(
				'%1$s://%2$s/api/v4/projects/%3$s/repository/archive.zip',
				$this->repository_protocol,
				$this->repository_host,
				rawurlencode( $this->user_name . '/' . $this->repository_name )
			);
			$url = add_query_arg( 'sha', rawurlencode( $ref ), $url );

			return $url;
		}

		/**
		 * Get a specific tag.
		 *
		 * @param string $tag_name
		 * @return void
		 */
		public function get_tag( $tag_name ) {
			throw new \LogicException( 'The ' . __METHOD__ . ' method is not implemented and should not be used.' );
		}

		protected function get_update_detection_strategies( $config_branch ) {
			$strategies = array();

			if ( ( 'main' === $config_branch ) || ( 'master' === $config_branch ) ) {
				$strategies[ self::STRATEGY_LATEST_RELEASE ] = array( $this, 'get_latest_release' );
				$strategies[ self::STRATEGY_LATEST_TAG ]     = array( $this, 'get_latest_tag' );
			}

			$strategies[ self::STRATEGY_BRANCH ] = function () use ( $config_branch ) {
				return $this->get_branch( $config_branch );
			};

			return $strategies;
		}

		public function set_authentication( $credentials ) {
			parent::set_authentication( $credentials );
			$this->access_token = is_string( $credentials ) ? $credentials : null;
		}

		protected function get_filterable_asset_name( $release_asset ) {

			if ( isset( $release_asset->url ) ) {
				return $release_asset->url;
			}

			return null;
		}

		/**
		 * Generate the value of the "Authorization" header.
		 *
		 * @return string
		 */
		public function get_authorization_headers() {
			return array( 'PRIVATE-TOKEN' => $this->access_token );
		}
	}

endif;
