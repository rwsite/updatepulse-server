<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! trait_exists( ReleaseAssetSupport::class, false ) ) :

	/**
	 * Trait ReleaseAssetSupport
	 *
	 * Provides functionality for handling release assets in version control systems.
	 * Implements methods for enabling, disabling, and filtering release assets
	 * during the update process.
	 */
	trait ReleaseAssetSupport {

		/**
		 * Whether to download release assets instead of the auto-generated source code archives.
		 *
		 * @var bool
		 */
		protected $release_assets_enabled = false;
		/**
		 * Regular expression that's used to filter release assets by file name or URL.
		 *
		 * @var string|null Optional regular expression for asset filtering.
		 */
		protected $asset_filter_regex = null;
		/**
		 * How to handle releases that don't have any matching release assets.
		 *
		 * Controls the behavior when no matching assets are found in a release.
		 * Uses Api::PREFER_RELEASE_ASSETS constant as default.
		 *
		 * @var int
		 */
		protected $release_asset_preference = Api::PREFER_RELEASE_ASSETS;

		/**
		 * Enable updating via release assets.
		 *
		 * If the latest release contains no usable assets, the update checker
		 * will fall back to using the automatically generated ZIP archive.
		 *
		 * @param string|null $name_regex Optional. Use only those assets where
		 *                               the file name or URL matches this regex.
		 * @param int        $preference Optional. How to handle releases that don't have
		 *                              any matching release assets.
		 * @return void
		 */
		public function enable_release_assets( $name_regex = null, $preference = Api::PREFER_RELEASE_ASSETS ) {
			$this->release_assets_enabled   = true;
			$this->asset_filter_regex       = $name_regex;
			$this->release_asset_preference = $preference;
		}

		/**
		 * Disable release assets.
		 *
		 * Disables the use of release assets and clears any existing asset filters.
		 *
		 * @return void
		 * @noinspection PhpUnused -- Public API
		 */
		public function disable_release_assets() {
			$this->release_assets_enabled = false;
			$this->asset_filter_regex     = null;
		}

		/**
		 * Does the specified asset match the name regex?
		 *
		 * Checks if a given release asset matches the configured name regex filter.
		 * If no filter is set, accepts all assets by default.
		 *
		 * @param mixed $release_asset Data type and structure depend on the host/API.
		 * @return bool True if the asset matches the filter or if no filter is set.
		 */
		protected function matches_asset_filter( $release_asset ) {

			if ( null === $this->asset_filter_regex ) {
				//The default is to accept all assets.
				return true;
			}

			$name = $this->get_filterable_asset_name( $release_asset );

			if ( ! is_string( $name ) ) {
				return false;
			}

			return (bool) preg_match( $this->asset_filter_regex, $release_asset->name );
		}

		/**
		 * Get the part of asset data that will be checked against the filter regex.
		 *
		 * @param mixed $release_asset The release asset object to extract the name from.
		 * @return string|null The filterable name of the asset or null if not available.
		 */
		abstract protected function get_filterable_asset_name( $release_asset );
	}

endif;
