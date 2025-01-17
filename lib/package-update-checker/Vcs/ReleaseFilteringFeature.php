<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! trait_exists( ReleaseFilteringFeature::class, false ) ) :

	trait ReleaseFilteringFeature {
		/**
		 * @var callable|null
		 */
		protected $release_filter_callback = null;
		/**
		 * @var int
		 */
		protected $release_filter_max_releases = 1;
		/**
		 * @var string One of the Api::RELEASE_FILTER_* constants.
		 */
		protected $release_filter_by_type = Api::RELEASE_FILTER_SKIP_PRERELEASE;

		/**
		 * Set a custom release filter.
		 *
		 * Setting a new filter will override the old filter, if any.
		 *
		 * @param callable $callback A callback that accepts a version number and a release
		 *                           object, and returns a boolean.
		 * @param int $releaseTypes  One of the Api::RELEASE_FILTER_* constants.
		 * @param int $maxReleases   Optional. The maximum number of recent releases to examine
		 *                           when trying to find a release that matches the filter. 1 to 100.
		 * @return $this
		 */
		public function set_release_filter(
			$callback,
			$release_types = Api::RELEASE_FILTER_SKIP_PRERELEASE,
			$max_releases = 20
		) {
			if ( $max_releases > 100 ) {
				throw new \InvalidArgumentException(
					sprintf(
						'The max number of releases is too high (%d). It must be 100 or less.',
						esc_html( $max_releases )
					)
				);
			} elseif ( $max_releases < 1 ) {
				throw new \InvalidArgumentException(
					sprintf(
						'The max number of releases is too low (%d). It must be at least 1.',
						esc_html( $max_releases )
					)
				);
			}

			$this->release_filter_callback     = $callback;
			$this->release_filter_by_type      = $release_types;
			$this->release_filter_max_releases = $max_releases;

			return $this;
		}

		/**
		 * Filter releases by their version number.
		 *
		 * @param string $regex A regular expression. The release version number must match this regex.
		 * @param int $releaseTypes
		 * @param int $maxReleasesToExamine
		 * @return $this
		 * @noinspection PhpUnused -- Public API
		 */
		public function set_release_version_filter(
			$regex,
			$release_types = Api::RELEASE_FILTER_SKIP_PRERELEASE,
			$max_releases_to_examine = 20
		) {
			return $this->setReleaseFilter(
				function ( $version_number ) use ( $regex ) {
					return ( preg_match( $regex, $version_number ) === 1 );
				},
				$release_types,
				$max_releases_to_examine
			);
		}

		/**
		 * @param string $versionNumber The detected release version number.
		 * @param object $releaseObject Varies depending on the host/API.
		 * @return bool
		 */
		protected function matches_custom_release_filter( $version_number, $release_object ) {

			if ( ! is_callable( $this->release_filter_callback ) ) {
				return true; //No custom filter.
			}

			return call_user_func( $this->release_filter_callback, $version_number, $release_object );
		}

		/**
		 * @return bool
		 */
		protected function should_skip_pre_releases() {
			//Maybe this could be a bitfield in the future, if we need to support
			//more release types.
			return ( Api::RELEASE_FILTER_ALL !== $this->release_filter_by_type );
		}

		/**
		 * @return bool
		 */
		protected function has_custom_release_filter() {
			return isset( $this->release_filter_callback ) && is_callable( $this->release_filter_callback );
		}
	}

endif;
