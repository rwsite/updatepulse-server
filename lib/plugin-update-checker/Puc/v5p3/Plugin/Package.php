<?php
namespace Anyape\PluginUpdateChecker\v5p3\Plugin;

use Anyape\PluginUpdateChecker\v5p3\InstalledPackage;

if ( !class_exists(Package::class, false) ):

	class Package extends InstalledPackage {
		/**
		 * @var UpdateChecker
		 */
		protected $updateChecker;

		/**
		 * @var string Full path of the main plugin file.
		 */
		protected $pluginAbsolutePath = '';

		public function __construct($pluginAbsolutePath, $updateChecker) {
			$this->pluginAbsolutePath = $pluginAbsolutePath;

			parent::__construct($updateChecker);

		}
		/**
		 * Get the value of a specific plugin or theme header.
		 *
		 * @param string $headerName
		 * @param string $defaultValue
		 * @return string Either the value of the header, or $defaultValue if the header doesn't exist or is empty.
		 */
		public function getHeaderValue($headerName, $defaultValue = '') {
			$headers = $this->getPluginHeader();
			if ( isset($headers[$headerName]) && ($headers[$headerName] !== '') ) {
				return $headers[$headerName];
			}
			return $defaultValue;
		}

		protected function getHeaderNames() {
			return array(
				'Name'              => 'Plugin Name',
				'PluginURI'         => 'Plugin URI',
				'Version'           => 'Version',
				'Description'       => 'Description',
				'Author'            => 'Author',
				'AuthorURI'         => 'Author URI',
				'TextDomain'        => 'Text Domain',
				'DomainPath'        => 'Domain Path',
				'Network'           => 'Network',

				//The newest WordPress version that this plugin requires or has been tested with.
				//We support several different formats for compatibility with other libraries.
				'Tested WP'         => 'Tested WP',
				'Requires WP'       => 'Requires WP',
				'Tested up to'      => 'Tested up to',
				'Requires at least' => 'Requires at least',
			);
		}
	}

endif;
