<?php
namespace Anyape\PluginUpdateChecker\v5p3\Plugin;

use Anyape\PluginUpdateChecker\v5p3\Update as BaseUpdate;

if ( !class_exists(Update::class, false) ):

	/**
	 * A simple container class for holding information about an available update.
	 *
	 * @author Janis Elsts
	 * @copyright 2016
	 * @access public
	 */
	class Update extends BaseUpdate {
		public $id = 0;
		public $homepage;
		public $upgrade_notice;
		public $tested;
		public $requires_php = false;
		public $icons = array();
		public $filename; //Plugin filename relative to the plugins directory.

		protected static $extraFields = array(
			'id', 'homepage', 'tested', 'requires_php', 'upgrade_notice', 'icons', 'filename',
		);

		/**
		 * Create a new instance of PluginUpdate from its JSON-encoded representation.
		 *
		 * @param string $json
		 * @return self|null
		 */
		public static function fromJson($json){
			//Since update-related information is simply a subset of the full plugin info,
			//we can parse the update JSON as if it was a plugin info string, then copy over
			//the parts that we care about.
			$pluginInfo = PluginInfo::fromJson($json);
			if ( $pluginInfo !== null ) {
				return self::fromPluginInfo($pluginInfo);
			} else {
				return null;
			}
		}

		/**
		 * Create a new instance of PluginUpdate based on an instance of PluginInfo.
		 * Basically, this just copies a subset of fields from one object to another.
		 *
		 * @param PluginInfo $info
		 * @return static
		 */
		public static function fromPluginInfo($info){
			return static::fromObject($info);
		}

		/**
		 * Create a new instance by copying the necessary fields from another object.
		 *
		 * @param \StdClass|PluginInfo|self $object The source object.
		 * @return self The new copy.
		 */
		public static function fromObject($object) {
			$update = new self();
			$update->copyFields($object, $update);
			return $update;
		}

		/**
		 * @return string[]
		 */
		protected function getFieldNames() {
			return array_merge(parent::getFieldNames(), self::$extraFields);
		}
	}

endif;
