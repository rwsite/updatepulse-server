<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PluginUpdateChecker\v5p3\Vcs;

use Anyape\PluginUpdateChecker\v5p3\OAuthSignature;

if ( !class_exists(BitBucketApi::class, false) ):

	class BitBucketApi extends Api {
		/**
		 * @var OAuthSignature
		 */
		private $oauth = null;

		/**
		 * @var string
		 */
		private $username;

		/**
		 * @var string
		 */
		private $repository;

		public function __construct($repository_url, $credentials = array()) {
			$path = wp_parse_url($repository_url, PHP_URL_PATH);
			if ( preg_match('@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@', $path, $matches) ) {
				$this->username = $matches['username'];
				$this->repository = $matches['repository'];
			} else {
				throw new \InvalidArgumentException('Invalid BitBucket repository URL: "' . $repository_url . '"');
			}

			parent::__construct($repository_url, $credentials);
		}

		protected function get_update_detection_strategies($config_branch) {
			$strategies = array(
				self::STRATEGY_STABLE_TAG => function () use ($config_branch) {
					return $this->getStableTag($config_branch);
				},
			);

			if ( ($config_branch === 'master' || $config_branch === 'main') ) {
				$strategies[self::STRATEGY_LATEST_TAG] = array($this, 'get_latest_tag');
			}

			$strategies[self::STRATEGY_BRANCH] = function () use ($config_branch) {
				return $this->get_branch($config_branch);
			};
			return $strategies;
		}

		public function get_branch($branch_name) {
			$branch = $this->api('/refs/branches/' . $branch_name);
			if ( is_wp_error($branch) || empty($branch) ) {
				return null;
			}

			//The "/src/{stuff}/{path}" endpoint doesn't seem to handle branch names that contain slashes.
			//If we don't encode the slash, we get a 404. If we encode it as "%2F", we get a 401.
			//To avoid issues, if the branch name is not URL-safe, let's use the commit hash instead.
			$ref = $branch->name;
			if ((urlencode($ref) !== $ref) && isset($branch->target->hash)) {
				$ref = $branch->target->hash;
			}

			return new Reference(array(
				'name' => $ref,
				'updated' => $branch->target->date,
				'download_url' => $this->get_download_url($branch->name),
			));
		}

		/**
		 * Get a specific tag.
		 *
		 * @param string $tag_name
		 * @return Reference|null
		 */
		public function get_tag($tag_name) {
			$tag = $this->api('/refs/tags/' . $tag_name);
			if ( is_wp_error($tag) || empty($tag) ) {
				return null;
			}

			return new Reference(array(
				'name' => $tag->name,
				'version' => ltrim($tag->name, 'v'),
				'updated' => $tag->target->date,
				'download_url' => $this->get_download_url($tag->name),
			));
		}

		/**
		 * Get the tag that looks like the highest version number.
		 *
		 * @return Reference|null
		 */
		public function get_latest_tag() {
			$tags = $this->api('/refs/tags?sort=-target.date');
			if ( !isset($tags, $tags->values) || !is_array($tags->values) ) {
				return null;
			}

			//Filter and sort the list of tags.
			$versionTags = $this->sort_tags_by_version($tags->values);

			//Return the first result.
			if ( !empty($versionTags) ) {
				$tag = $versionTags[0];
				return new Reference(array(
					'name' => $tag->name,
					'version' => ltrim($tag->name, 'v'),
					'updated' => $tag->target->date,
					'download_url' => $this->get_download_url($tag->name),
				));
			}
			return null;
		}

		/**
		 * Get the tag/ref specified by the "Stable tag" header in the readme.txt of a given branch.
		 *
		 * @param string $branch
		 * @return null|Reference
		 */
		protected function getStableTag($branch) {
			$remoteReadme = $this->get_remote_readme($branch);
			if ( !empty($remoteReadme['stable_tag']) ) {
				$tag = $remoteReadme['stable_tag'];

				//You can explicitly opt out of using tags by setting "Stable tag" to
				//"trunk" or the name of the current branch.
				if ( ($tag === $branch) || ($tag === 'trunk') ) {
					return $this->get_branch($branch);
				}

				return $this->get_tag($tag);
			}

			return null;
		}

		/**
		 * @param string $ref
		 * @return string
		 */
		protected function get_download_url($ref) {
			return sprintf(
				'https://bitbucket.org/%s/%s/get/%s.zip',
				$this->username,
				$this->repository,
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
		public function get_remote_file($path, $ref = 'master') {
			$response = $this->api('src/' . $ref . '/' . ltrim($path));
			if ( is_wp_error($response) || !is_string($response) ) {
				return null;
			}
			return $response;
		}

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g. branch or tag).
		 * @return string|null
		 */
		public function get_latest_commit_time($ref) {
			$response = $this->api('commits/' . $ref);
			if ( isset($response->values, $response->values[0], $response->values[0]->date) ) {
				return $response->values[0]->date;
			}
			return null;
		}

		/**
		 * Perform a BitBucket API 2.0 request.
		 *
		 * @param string $url
		 * @param string $version
		 * @return mixed|\WP_Error
		 */
		public function api($url, $version = '2.0') {
			$url = ltrim($url, '/');
			$isSrcResource = 0 === strpos($url, 'src/');

			$url = implode('/', array(
				'https://api.bitbucket.org',
				$version,
				'repositories',
				$this->username,
				$this->repository,
				$url
			));
			$baseUrl = $url;

			if ( $this->oauth ) {
				$url = $this->oauth->sign($url,'GET');
			}

			$options  = array('timeout' => wp_doing_cron() ? 10 : 3);
			$response = wp_remote_get($url, $options);
			if ( is_wp_error($response) ) {
				do_action('puc_api_error', $response, null, $url, $this->slug);
				return $response;
			}

			$code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);
			if ( $code === 200 ) {
				if ( $isSrcResource ) {
					//Most responses are JSON-encoded, but src resources just
					//return raw file contents.
					$document = $body;
				} else {
					$document = json_decode($body);
				}
				return $document;
			}

			$error = new \WP_Error(
				'puc-bitbucket-http-error',
				sprintf('BitBucket API error. Base URL: "%s",  HTTP status code: %d.', $baseUrl, $code)
			);
			do_action('puc_api_error', $error, $response, $url, $this->slug);

			return $error;
		}

		/**
		 * @param array $credentials
		 */
		public function set_authentication($credentials) {
			parent::set_authentication($credentials);

			if ( !empty($credentials) && !empty($credentials['consumer_key']) ) {
				$this->oauth = new OAuthSignature(
					$credentials['consumer_key'],
					$credentials['consumer_secret']
				);
			} else {
				$this->oauth = null;
			}
		}

		public function sign_download_url($url) {
			//Add authentication data to download URLs. Since OAuth signatures incorporate
			//timestamps, we have to do this immediately before inserting the update. Otherwise,
			//authentication could fail due to a stale timestamp.
			if ( $this->oauth ) {
				$url = $this->oauth->sign($url);
			}
			return $url;
		}
	}

endif;
