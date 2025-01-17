<?php

namespace Anyape\ProxyUpdateChecker\Vcs;

use Anyape\PluginUpdateChecker\v5p3\Vcs\BaseChecker;
use Anyape\PluginUpdateChecker\v5p3\Theme\Package;
use Anyape\PluginUpdateChecker\v5p3\Theme\Update;
use Anyape\PluginUpdateChecker\v5p3\UpdateChecker;

if (! class_exists(ThemeUpdateChecker::class, false)):

	class ThemeUpdateChecker extends UpdateChecker implements BaseChecker {
		public $themeAbsolutePath = '';
		protected $stylesheet;
		protected $branch = 'main';
		protected $api = null;
		protected $package = null;

		public function __construct($api, $slug, $container) {
			$this->api = $api;
			$this->stylesheet = $slug;
			$this->themeAbsolutePath = trailingslashit($container) . $slug;
			$this->debugMode = (bool)(constant('WP_DEBUG'));
			$this->metadataUrl = $api->getRepositoryUrl();
			$this->directoryName = basename(dirname($this->themeAbsolutePath));
			$this->slug = !empty($slug) ? $slug : $this->directoryName;
			$this->package = new Package($this->themeAbsolutePath, $this);
			$this->api->setSlug($this->slug);
		}

		public function requestInfo($unused = null) {
			$api = $this->api;
			$api->setLocalDirectory(trailingslashit($this->themeAbsolutePath));

			$update = new Update();
			$update->slug = $this->slug;
			$update->version = null;

			//Figure out which reference (tag or branch) we'll use to get the latest version of the theme.
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
				array( 'slug' => $this->slug ),
				$this->api,
				$ref,
				$this
			);

			if ( is_array( $info ) && isset( $info['abort_request'] ) && $info['abort_request'] ) {
				return $info;
			}

			//Get headers from the main stylesheet in this branch/tag. Its "Version" header and other metadata
			//are what the WordPress install will actually see after upgrading, so they take precedence over releases/tags.
			$file = $api->getRemoteFile('style.css', $ref);

			if (!empty($file)) {
				$remoteHeader = $this->package->getFileHeader($file);
				$update->version = empty( $remoteHeader['Version'] ) ? $updateSource->version : $remoteHeader['Version'];
			}

			if (empty($update->version)) {
				//It looks like we didn't find a valid update after all.
				$update = null;
			}

			if ($update && 'source_not_found' !== $update) {

				if (!empty($update->download_url)) {
					$update->download_url = $this->api->signDownloadUrl($update->download_url);
				}

				$info = is_array($info) ? $info : array();
				$info = array_merge(
					$info,
					array(
						'type'         => 'Theme',
						'version'      => $update->version,
						'main_file'    => 'style.css',
						'download_url' => $update->download_url,
					)
				);
			} elseif ('source_not_found' === $update) {
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
				$api,
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