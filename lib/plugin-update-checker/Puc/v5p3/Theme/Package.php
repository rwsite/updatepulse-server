<?php
namespace Anyape\PluginUpdateChecker\v5p3\Theme;

use Anyape\PluginUpdateChecker\v5p3\InstalledPackage;

if ( !class_exists(Package::class, false) ):

	class Package extends InstalledPackage {
		/**
		 * @var string Theme directory name.
		 */
		protected $stylesheet;


		public function __construct($stylesheet, $updateChecker) {
			$this->stylesheet = $stylesheet;

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
			$value = $this->theme->get($headerName);
			if ( ($headerName === false) || ($headerName === '') ) {
				return $defaultValue;
			}
			return $value;
		}

		protected function getHeaderNames() {
			return array(
				'Name'        => 'Theme Name',
				'ThemeURI'    => 'Theme URI',
				'Description' => 'Description',
				'Author'      => 'Author',
				'AuthorURI'   => 'Author URI',
				'Version'     => 'Version',
				'Template'    => 'Template',
				'Status'      => 'Status',
				'Tags'        => 'Tags',
				'TextDomain'  => 'Text Domain',
				'DomainPath'  => 'Domain Path',
			);
		}
	}

endif;
