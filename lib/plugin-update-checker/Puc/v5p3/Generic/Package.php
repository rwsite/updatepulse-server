<?php

namespace Anyape\PluginUpdateChecker\v5p3\Generic;

use Anyape\PluginUpdateChecker\v5p3\InstalledPackage;

if (!class_exists(Package::class, false)):

	class Package extends InstalledPackage {

		public function getHeaderValue($headerName, $defaultValue = '') {
			return $defaultValue;
		}

		protected function getHeaderNames() {
			return array();
		}
	}

endif;
