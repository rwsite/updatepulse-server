<?php

namespace Anyape\ProxyUpdateChecker;

if ( ! class_exists(Proxuc_Autoloader::class, false) ):

	class Proxuc_Autoloader {
		private $prefix = '';
		private $rootDir = '';

		public function __construct() {
			$this->rootDir = dirname(__FILE__) . '/';
			$this->prefix = __NAMESPACE__ . '\\';

			spl_autoload_register(array($this, 'autoload'));
		}

		public function autoload($className) {
			$path = substr($className, strlen($this->prefix));
			$path = str_replace(array('_', '\\'), '/', $path);

			if ( strpos($className, $this->prefix) === 0 ) {
				$path = substr($className, strlen($this->prefix));
				$path = str_replace(array('_', '\\'), '/', $path);
				$path = $this->rootDir . $path . '.php';

				if ( file_exists($path) ) {
					include $path;
				}
			}
		}
	}

endif;