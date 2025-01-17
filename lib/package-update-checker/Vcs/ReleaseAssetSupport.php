<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! trait_exists( ReleaseAssetSupport::class, false ) ) :

	trait ReleaseAssetSupport {
		/**
		 * @var bool Whether to download release assets instead of the auto-generated
		 *           source code archives.
		 */
		protected $release_assets_enabled = false;

		/**
		 * @var string|null Regular expression that's used to filter release assets
		 *                  by file name or URL. Optional.
		 */
		protected $asset_filter_regex = null;

		/**
		 * How to handle releases that don't have any matching release assets.
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
		 * @param string|null $nameRegex Optional. Use only those assets where
		 *                               the file name or URL matches this regex.
		 * @param int $preference Optional. How to handle releases that don't have
		 *                        any matching release assets.
		 */
		public function enable_release_assets( $name_regex = null, $preference = Api::PREFER_RELEASE_ASSETS ) {
			$this->release_assets_enabled   = true;
			$this->asset_filter_regex       = $name_regex;
			$this->release_asset_preference = $preference;
		}

		/**
		 * Disable release assets.
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
		 * @param mixed $releaseAsset Data type and structure depend on the host/API.
		 * @return bool
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
		 * @param mixed $releaseAsset
		 * @return string|null
		 */
		abstract protected function get_filterable_asset_name( $release_asset );
	}

endif;
