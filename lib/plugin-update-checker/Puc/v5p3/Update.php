<?php
namespace Anyape\PluginUpdateChecker\v5p3;

if ( !class_exists(Update::class, false) ):

	/**
	 * A simple container class for holding information about an available update.
	 *
	 * @author Janis Elsts
	 * @access public
	 */
	abstract class Update extends Metadata {
		public $slug;
		public $version;
		public $download_url;
		public $translations = array();

		/**
		 * @return string[]
		 */
		protected function getFieldNames() {
			error_log( __METHOD__ . '::' . __LINE__ );
			return array('slug', 'version', 'download_url', 'translations');
		}
	}

endif;
