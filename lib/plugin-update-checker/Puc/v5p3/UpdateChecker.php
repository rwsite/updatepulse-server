<?php
namespace YahnisElsts\PluginUpdateChecker\v5p3;

use stdClass;
use WP_Error;

if ( !class_exists(UpdateChecker::class, false) ):

	abstract class UpdateChecker {
		protected $filterSuffix = '';

		/**
		 * Set to TRUE to enable error reporting. Errors are raised using trigger_error()
		 * and should be logged to the standard PHP error log.
		 * @var bool
		 */
		public $debugMode = null;

		/**
		 * @var string Where to store the update info.
		 */
		public $optionName = '';

		/**
		 * @var string The URL of the metadata file.
		 */
		public $metadataUrl = '';

		/**
		 * @var string Plugin or theme directory name.
		 */
		public $directoryName = '';

		/**
		 * @var string The slug that will be used in update checker hooks and remote API requests.
		 * Usually matches the directory name unless the plugin/theme directory has been renamed.
		 */
		public $slug = '';


		public function __construct($metadataUrl, $directoryName, $slug = null, $checkPeriod = 12, $optionName = '') {
			// error_log( __METHOD__ . '::' . __LINE__ );
			$this->debugMode = (bool)(constant('WP_DEBUG'));
			$this->metadataUrl = $metadataUrl;
			$this->directoryName = $directoryName;
			$this->slug = !empty($slug) ? $slug : $this->directoryName;

			$this->optionName = $optionName;
			if ( empty($this->optionName) ) {
				//BC: Initially the library only supported plugin updates and didn't use type prefixes
				//in the option name. Lets use the same prefix-less name when possible.
				if ( $this->filterSuffix === '' ) {
					$this->optionName = 'external_updates-' . $this->slug;
				} else {
					$this->optionName = $this->getUniqueName('external_updates');
				}
			}

		}

		/**
		 * Trigger a PHP error, but only when $debugMode is enabled.
		 *
		 * @param string $message
		 * @param int $errorType
		 */
		public function triggerError($message, $errorType) {
			// error_log( __METHOD__ . '::' . __LINE__ );
			if ( $this->isDebugModeEnabled() ) {
				//phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- Only happens in debug mode.
				trigger_error(esc_html($message), $errorType);
			}
		}

		/**
		 * @return bool
		 */
		protected function isDebugModeEnabled() {
			// error_log( __METHOD__ . '::' . __LINE__ );
			if ( $this->debugMode === null ) {
				$this->debugMode = (bool)(constant('WP_DEBUG'));
			}
			return $this->debugMode;
		}

		/**
		 * Get the full name of an update checker filter, action or DB entry.
		 *
		 * This method adds the "puc_" prefix and the "-$slug" suffix to the filter name.
		 * For example, "pre_inject_update" becomes "puc_pre_inject_update-plugin-slug".
		 *
		 * @param string $baseTag
		 * @return string
		 */
		public function getUniqueName($baseTag) {
			// error_log( __METHOD__ . '::' . __LINE__ );
			$name = 'puc_' . $baseTag;
			if ( $this->filterSuffix !== '' ) {
				$name .= '_' . $this->filterSuffix;
			}
			return $name . '-' . $this->slug;
		}

		/* -------------------------------------------------------------------
		 * PUC filters and filter utilities
		 * -------------------------------------------------------------------
		 */

		/**
		 * Register a callback for one of the update checker filters.
		 *
		 * Identical to add_filter(), except it automatically adds the "puc_" prefix
		 * and the "-$slug" suffix to the filter name. For example, "request_info_result"
		 * becomes "puc_request_info_result-your_plugin_slug".
		 *
		 * @param string $tag
		 * @param callable $callback
		 * @param int $priority
		 * @param int $acceptedArgs
		 */
		public function addFilter($tag, $callback, $priority = 10, $acceptedArgs = 1) {
			// error_log( __METHOD__ . '::' . __LINE__ );
			add_filter($this->getUniqueName($tag), $callback, $priority, $acceptedArgs);
		}


		/* -------------------------------------------------------------------
		 * JSON-based update API
		 * -------------------------------------------------------------------
		 */

		/**
		 * Retrieve plugin or theme metadata from the JSON document at $this->metadataUrl.
		 *
		 * @param class-string<Update> $metaClass Parse the JSON as an instance of this class. It must have a static fromJson method.
		 * @param string $filterRoot
		 * @param array $queryArgs Additional query arguments.
		 * @return array<Metadata|null, array|WP_Error> A metadata instance and the value returned by wp_remote_get().
		 */
		protected function requestMetadata($metaClass, $filterRoot, $queryArgs = array()) {
			// error_log( __METHOD__ . '::' . __LINE__ );
			//Query args to append to the URL. Plugins can add their own by using a filter callback (see addQueryArgFilter()).
			$queryArgs = array_merge(
				array(
					'installed_version' => strval($this->getInstalledVersion()),
					'php' => phpversion(),
					'locale' => get_locale(),
				),
				$queryArgs
			);
			$queryArgs = apply_filters($this->getUniqueName($filterRoot . '_query_args'), $queryArgs);

			//Various options for the wp_remote_get() call. Plugins can filter these, too.
			$options = array(
				'timeout' => wp_doing_cron() ? 10 : 3,
				'headers' => array(
					'Accept' => 'application/json',
				),
			);
			$options = apply_filters($this->getUniqueName($filterRoot . '_options'), $options);

			//The metadata file should be at 'http://your-api.com/url/here/$slug/info.json'
			$url = $this->metadataUrl;
			if ( !empty($queryArgs) ){
				$url = add_query_arg($queryArgs, $url);
			}

			$result = wp_remote_get($url, $options);

			$result = apply_filters($this->getUniqueName('request_metadata_http_result'), $result, $url, $options);

			//Try to parse the response
			$status = $this->validateApiResponse($result);
			$metadata = null;
			if ( !is_wp_error($status) ){
				if ( (strpos($metaClass, '\\') === false) ) {
					$metaClass = __NAMESPACE__ . '\\' . $metaClass;
				}
				$metadata = call_user_func(array($metaClass, 'fromJson'), $result['body']);
			} else {
				do_action('puc_api_error', $status, $result, $url, $this->slug);
				$this->triggerError(
					sprintf('The URL %s does not point to a valid metadata file. ', $url)
					. $status->get_error_message(),
					E_USER_WARNING
				);
			}

			return array($metadata, $result);
		}

		/**
		 * Check if $result is a successful update API response.
		 *
		 * @param array|WP_Error $result
		 * @return true|WP_Error
		 */
		protected function validateApiResponse($result) {
			// error_log( __METHOD__ . '::' . __LINE__ );
			if ( is_wp_error($result) ) { /** @var WP_Error $result */
				return new WP_Error($result->get_error_code(), 'WP HTTP Error: ' . $result->get_error_message());
			}

			if ( !isset($result['response']['code']) ) {
				return new WP_Error(
					'puc_no_response_code',
					'wp_remote_get() returned an unexpected result.'
				);
			}

			if ( $result['response']['code'] !== 200 ) {
				return new WP_Error(
					'puc_unexpected_response_code',
					'HTTP response code is ' . $result['response']['code'] . ' (expected: 200)'
				);
			}

			if ( empty($result['body']) ) {
				return new WP_Error('puc_empty_response', 'The metadata file appears to be empty.');
			}

			return true;
		}

	}

endif;
