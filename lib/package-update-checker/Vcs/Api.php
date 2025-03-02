<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! class_exists( Api::class, false ) ) :

	/**
	 * Abstract class representing a Version Control System (VCS) API.
	 */
	abstract class Api {

		const STRATEGY_LATEST_RELEASE = 'latest_release';
		const STRATEGY_LATEST_TAG     = 'latest_tag';
		const STRATEGY_STABLE_TAG     = 'stable_tag';
		const STRATEGY_BRANCH         = 'branch';
		/**
		 * Consider all releases regardless of their version number or prerelease/upcoming
		 * release status.
		 */
		const RELEASE_FILTER_ALL = 3;
		/**
		 * Exclude releases that have the "prerelease" or "upcoming release" flag.
		 *
		 * This does *not* look for prerelease keywords like "beta" in the version number.
		 * It only uses the data provided by the API. For example, on GitHub, you can
		 * manually mark a release as a prerelease.
		 */
		const RELEASE_FILTER_SKIP_PRERELEASE = 1;
		/**
		 * If there are no release assets or none of them match the configured filter,
		 * fall back to the automatically generated source code archive.
		 */
		const PREFER_RELEASE_ASSETS = 1;
		/**
		 * Skip releases that don't have any matching release assets.
		 */
		const REQUIRE_RELEASE_ASSETS = 2;

		/**
		 * @var string
		 */
		protected $tag_name_property = 'name';
		/**
		 * @var string
		 */
		protected $slug = '';
		/**
		 * @var string
		 */
		protected $repository_url = '';
		/**
		 * @var string GitHub repository name.
		 */
		protected $repository_name;
		/**
		 * @var string
		 */
		protected $user_name;
		/**
		 * @var mixed Authentication details for private repositories. Format depends on service.
		 */
		protected $credentials = null;
		/**
		 * @var string|null
		 */
		protected $local_directory = null;

		/**
		 * Api constructor.
		 *
		 * @param string $repository_url
		 * @param array|string|null $credentials
		 */
		public function __construct( $repository_url, $credentials = null ) {
			$this->repository_url = $repository_url;

			$this->set_authentication( $credentials );
		}

		/**
		 * @return string
		 */
		public function get_repository_url() {
			return $this->repository_url;
		}

		/**
		 * Determine which reference (i.e., tag or branch) contains the latest version.
		 *
		 * @param string $config_branch Start looking in this branch.
		 * @return null|Reference
		 */
		public function choose_reference( $config_branch ) {
			$strategies = $this->get_update_detection_strategies( $config_branch );

			foreach ( $strategies as $strategy ) {
				$reference = call_user_func( $strategy );

				if ( ! empty( $reference ) ) {
					return $reference;
				}
			}

			return null;
		}

		/**
		 * Get an ordered list of strategies that can be used to find the latest version.
		 *
		 * The update checker will try each strategy in order until one of them
		 * returns a valid reference.
		 *
		 * @param string $config_branch
		 * @return array<callable> Array of callables that return Vcs_Reference objects.
		 */
		abstract protected function get_update_detection_strategies( $config_branch );

		/**
		 * Get the case-sensitive name of the local readme.txt file.
		 *
		 * In most cases it should just be called "readme.txt", but some plugins call it "README.txt",
		 * "README.TXT", or even "Readme.txt". Most VCS are case-sensitive so we need to know the correct
		 * capitalization.
		 *
		 * Defaults to "readme.txt" (all lowercase).
		 *
		 * @return string
		 */
		public function get_local_readme_name() {
			static $file_name = null;

			if ( null !== $file_name ) {
				return $file_name;
			}

			$file_name = 'readme.txt';

			if ( isset( $this->local_directory ) ) {
				$files = scandir( $this->local_directory );

				if ( ! empty( $files ) ) {

					foreach ( $files as $possible_file_name ) {

						if ( strcasecmp( $possible_file_name, 'readme.txt' ) === 0 ) {
							$file_name = $possible_file_name;

							break;
						}
					}
				}
			}

			return $file_name;
		}

		/**
		 * Get a branch.
		 *
		 * @param string $branch_name
		 * @return Reference|null
		 */
		abstract public function get_branch( $branch_name );

		/**
		 * Get a specific tag.
		 *
		 * @param string $tag_name
		 * @return Reference|null
		 */
		abstract public function get_tag( $tag_name );

		/**
		 * Get the tag that looks like the highest version number.
		 * (Implementations should skip pre-release versions if possible.)
		 *
		 * @return Reference|null
		 */
		abstract public function get_latest_tag();

		/**
		 * Check if a tag name string looks like a version number.
		 *
		 * @param string $name
		 * @return bool
		 */
		protected function looks_like_version( $name ) {
			// Tag names may be prefixed with "v", e.g., "v1.2.3".
			$name = ltrim( $name, 'v' );

			// The version string must start with a number.
			if ( ! is_numeric( substr( $name, 0, 1 ) ) ) {
				return false;
			}

			// The goal is to accept any SemVer-compatible or "PHP-standardized" version number.
			return ( preg_match( '@^(\d{1,5}?)(\.\d{1,10}?){0,4}?($|[abrdp+_\-]|\s)@i', $name ) === 1 );
		}

		/**
		 * Check if a tag appears to be named like a version number.
		 *
		 * @param \stdClass $tag
		 * @return bool
		 */
		protected function is_version_tag( $tag ) {
			$property = $this->tag_name_property;

			return isset( $tag->$property ) && $this->looks_like_version( $tag->$property );
		}

		/**
		 * Sort a list of tags as if they were version numbers.
		 * Tags that don't look like version numbers will be removed.
		 *
		 * @param \stdClass[] $tags Array of tag objects.
		 * @return \stdClass[] Filtered array of tags sorted in descending order.
		 */
		protected function sort_tags_by_version( $tags ) {
			// Keep only those tags that look like version numbers.
			$version_tags = array_filter( $tags, array( $this, 'is_version_tag' ) );

			// Sort them in descending order.
			usort( $version_tags, array( $this, 'compare_tag_names' ) );

			return $version_tags;
		}

		/**
		 * Compare two tags as if they were version numbers.
		 *
		 * @param \stdClass $tag1 Tag object.
		 * @param \stdClass $tag2 Another tag object.
		 * @return int
		 */
		protected function compare_tag_names( $tag1, $tag2 ) {
			$property = $this->tag_name_property;

			if ( ! isset( $tag1->$property ) ) {
				return 1;
			}

			if ( ! isset( $tag2->$property ) ) {
				return -1;
			}

			return -version_compare( ltrim( $tag1->$property, 'v' ), ltrim( $tag2->$property, 'v' ) );
		}

		/**
		 * Get the contents of a file from a specific branch or tag.
		 *
		 * @param string $path File name.
		 * @param string $ref
		 * @return null|string Either the contents of the file, or null if the file doesn't exist or there's an error.
		 */
		abstract public function get_remote_file( $path, $ref = 'main' );

		/**
		 * Get the timestamp of the latest commit that changed the specified branch or tag.
		 *
		 * @param string $ref Reference name (e.g., branch or tag).
		 * @return string|null
		 */
		abstract public function get_latest_commit_time( $ref );

		/**
		 * Set authentication credentials.
		 *
		 * @param $credentials
		 */
		public function set_authentication( $credentials ) {
			$this->credentials = $credentials ? $credentials : null;
		}

		/**
		 * Check if authentication is enabled.
		 *
		 * @return bool
		 */
		public function is_authentication_enabled() {
			return ! empty( $this->credentials );
		}

		/**
		 * @param string $directory
		 */
		public function set_local_directory( $directory ) {

			if ( empty( $directory ) || ! is_dir( $directory ) || ( '.' === $directory ) ) {
				$this->local_directory = null;
			} else {
				$this->local_directory = $directory;
			}
		}

		/**
		 * @param string $slug
		 */
		public function set_slug( $slug ) {
			$this->slug = $slug;
		}

		/**
		 * Generate the value of the "Authorization" header.
		 *
		 * @return string
		 */
		public function get_authorization_headers() {
			return false;
		}
	}

endif;
