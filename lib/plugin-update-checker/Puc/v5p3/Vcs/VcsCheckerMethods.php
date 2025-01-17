<?php

namespace Anyape\PluginUpdateChecker\v5p3\Vcs;

if ( !trait_exists(VcsCheckerMethods::class, false) ) :

	trait VcsCheckerMethods {
		/**
		 * @var string The branch where to look for updates. Defaults to "master".
		 */
		protected $branch = 'master';

		/**
		 * @var Api Repository API client.
		 */
		protected $api = null;

		public function setBranch($branch) {
			$this->branch = $branch;
			return $this;
		}

		/**
		 * Set authentication credentials.
		 *
		 * @param array|string $credentials
		 * @return $this
		 */
		public function set_authentication($credentials) {
			$this->api->set_authentication($credentials);
			return $this;
		}

		/**
		 * @return Api
		 */
		public function getVcsApi() {
			return $this->api;
		}

		public function onDisplayConfiguration($panel) {
			parent::onDisplayConfiguration($panel);
			$panel->row('Branch', $this->branch);
			$panel->row('Authentication enabled', $this->api->is_authentication_enabled() ? 'Yes' : 'No');
			$panel->row('API client', get_class($this->api));
		}
	}

endif;