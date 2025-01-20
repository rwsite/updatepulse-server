<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker\Vcs;

if ( ! trait_exists( VcsCheckerMethods::class, false ) ) :

	trait VcsCheckerMethods {
		/**
		 * @var string The branch where to look for updates. Defaults to "main".
		 */
		protected $branch = 'main';

		/**
		 * @var Api Repository API client.
		 */
		protected $api = null;

		public function set_branch( $branch ) {
			$this->branch = $branch;

			return $this;
		}

		/**
		 * Set authentication credentials.
		 *
		 * @param array|string $credentials
		 * @return $this
		 */
		public function set_authentication( $credentials ) {
			$this->api->set_authentication( $credentials );

			return $this;
		}

		/**
		 * @return Api
		 */
		public function get_vcs_api() {
			return $this->api;
		}

		public function on_display_configuration( $panel ) {
			parent::on_display_configuration( $panel );

			$panel->row( 'Branch', $this->branch );
			$panel->row( 'Authentication enabled', $this->api->is_authentication_enabled() ? 'Yes' : 'No' );
			$panel->row( 'API client', get_class( $this->api ) );
		}
	}

endif;
