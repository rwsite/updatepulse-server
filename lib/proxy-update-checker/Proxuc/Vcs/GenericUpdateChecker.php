<?php

namespace Anyape\ProxyUpdateChecker\Vcs;

use YahnisElsts\PluginUpdateChecker\v5p3\Utils;
use YahnisElsts\PluginUpdateChecker\v5p3\Vcs\BaseChecker;
use Anyape\ProxyUpdateChecker\Generic\Package;
use Anyape\ProxyUpdateChecker\Generic\UpdateChecker;
use Anyape\ProxyUpdateChecker\Generic\Update;

if ( ! class_exists(GenericUpdateChecker::class, false) ):

	class GenericUpdateChecker extends UpdateChecker implements BaseChecker {
		public $genericAbsolutePath = '';

		protected $branch = 'main';
		protected $api = null;

		public function __construct($api, $slug, $file_name, $container) {
			$this->api = $api;
			$this->api->setHttpFilterName('puc_generic_request_update_options');
			$this->genericAbsolutePath = trailingslashit($container) . $slug;
			$this->genericFile = $slug . '/' . $file_name . '.json';
			$this->debugMode = (bool)(constant('WP_DEBUG'));
			$this->metadataUrl = $api->getRepositoryUrl();
			$this->directoryName = basename(dirname($this->genericAbsolutePath));
			$this->slug = !empty($slug) ? $slug : $this->directoryName;
			$this->optionName = 'external_updates-' . $this->slug;
			$this->package = new Package($this->genericAbsolutePath, $this);
			$this->api->setSlug($this->slug);
		}

		public function Vcs_getAbsoluteDirectoryPath() {
			return trailingslashit($this->genericAbsolutePath);
		}

		public function requestInfo() {
			$api = $this->api;

			$api->setLocalDirectory($this->Vcs_getAbsoluteDirectoryPath());

			$update = new Update();
			$update->slug = $this->slug;
			$update->version = null;
			$updateSource = $api->chooseReference($this->branch);

			if ($updateSource) {
				$ref = $updateSource->name;
				$update->download_url = $updateSource->downloadUrl;
			} else {
				return 'source_not_found';
			}

			/**
			 * Pre-filter the info that will be returned by the update checker.
			 *
			 * @param array $info The info that will be returned by the update checker.
			 * @param YahnisElsts\PluginUpdateChecker\v5p3\Vcs\API $api The API object that was used to fetch the info.
			 * @param string $ref The tag or branch that was used to fetch the info.
			 * @param YahnisElsts\PluginUpdateChecker\v5p3\UpdateChecker $checker The update checker object calling this filter.
			 */
			$info = apply_filters(
				'puc_request_info_pre_filter',
				null,
				$this->api,
				$ref,
				$this
			);

			if ( is_array( $info ) && isset( $info['abort_request'] ) && $info['abort_request'] ) {
				return $info;
			}

			$file = $api->getRemoteFile(basename($this->genericFile), $ref);

			if (!empty($file)) {
				$fileContents = json_decode($file, true);

				if (isset($fileContents['packageData']) && !empty($fileContents['packageData'])) {
					$remoteHeader = $fileContents['packageData'];
					$update->version = Utils::findNotEmpty(array(
						$remoteHeader['Version'],
						Utils::get($updateSource, 'version'),
					));
				}
			}

			if (empty($update->version)) {
				$update = null;
			}

			$update = $this->filterUpdateResult($update);

			if ($update && 'source_not_found' !== $update) {

				if (!empty($update->download_url)) {
					$update->download_url = $this->api->signDownloadUrl($update->download_url);
				}

				$info = is_array( $info ) ? $info : array();
				$info = array_merge(
					$info,
					array(
						'type'         => 'Generic',
						'version'      => $update->version,
						'main_file'    => $this->genericFile,
						'download_url' => $update->download_url,
					)
				);
			} elseif ( 'source_not_found' === $update ) {
				return new \WP_Error(
					'puc-no-update-source',
					'Could not retrieve version information from the repository for '
					. $this->slug . '.'
					. 'This usually means that the update checker either can\'t connect '
					. 'to the repository or it\'s configured incorrectly.'
				);
			}

			/**
			 * Filter the info that will be returned by the update checker.
			 *
			 * @param array $info The info that will be returned by the update checker.
			 * @param YahnisElsts\PluginUpdateChecker\v5p3\Vcs\API $api The API object that was used to fetch the info.
			 * @param string $ref The tag or branch that was used to fetch the info.
			 * @param YahnisElsts\PluginUpdateChecker\v5p3\UpdateChecker $checker The update checker object calling this filter.
			 */
			$info = apply_filters(
				'puc_request_info_result',
				$info,
				$this->api,
				$ref,
				$this
			);

			return $info;
		}

		public function setBranch($branch) {
			$this->branch = $branch;

			return $this;
		}

		public function setAuthentication($credentials) {
			$this->api->setAuthentication($credentials);

			return $this;
		}

		public function getVcsApi() {
			return $this->api;
		}
	}

endif;
