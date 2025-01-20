<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( PluginUpdateChecker::class, false ) ) :

	class PluginUpdateChecker extends UpdateChecker {
		public $plugin_absolute_path = ''; //Full path of the main plugin file.
		public $plugin_file          = '';  //Plugin filename relative to the plugins directory. Many WP APIs use this to identify plugins.

		public function __construct( $api, $slug, $file_name, $container ) {
			$this->api                  = $api;
			$this->plugin_absolute_path = trailingslashit( $container ) . $slug;
			$this->plugin_file          = $slug . '/' . $file_name . '.php';
			$this->debug_mode           = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name       = basename( dirname( $this->plugin_absolute_path ) );
			$this->slug                 = $slug;

			$this->api->set_slug( $this->slug );
		}

		public function request_info() {
			//We have to make several remote API requests to gather all the necessary info
			//which can take a while on slow networks.
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$this->api->set_local_directory( trailingslashit( $this->plugin_absolute_path ) );

			$update_source = $this->api->choose_reference( $this->branch );

			if ( $update_source ) {
				$ref = $update_source->name;
			} else {
				//There's probably a network problem or an authentication error.
				return new \WP_Error(
					'puc-no-update-source',
					'Could not retrieve version information from the repository for '
					. $this->slug . '.'
					. 'This usually means that the update checker either can\'t connect '
					. 'to the repository or it\'s configured incorrectly.'
				);
			}

			/**
			 * Pre-filter the info that will be returned by the update checker.
			 *
			 * @param array $info The info that will be returned by the update checker.
			 * @param YahnisElsts\PackageUpdateChecker\Vcs\API $api The API object that was used to fetch the info.
			 * @param string $ref The tag or branch that was used to fetch the info.
			 * @param YahnisElsts\PackageUpdateChecker\UpdateChecker $checker The update checker object calling this filter.
			 */
			$info = apply_filters(
				'puc_request_info_pre_filter',
				array( 'slug' => $this->slug ),
				$this->api,
				$ref,
				$this
			);
			$info = is_array( $info ) ? $info : array();

			if ( isset( $info['abort_request'] ) && $info['abort_request'] ) {
				return $info;
			}

			$file = $this->api->get_remote_file( basename( $this->plugin_file ), $ref );

			if ( ! empty( $remote_plugin ) ) {
				$remote_header   = $this->get_file_header( $file );
				$info['version'] = empty( $remote_header['Version'] ) ? $update_source->version : $remote_header['Version'];
			}

			$info['download_url'] = $this->api->sign_download_url( $update_source->download_url );
			$info['type']         = 'Plugin';
			$info['main_file']    = $this->plugin_file;

			/**
			 * Filter the info that will be returned by the update checker.
			 *
			 * @param array $info The info that will be returned by the update checker.
			 * @param YahnisElsts\PackageUpdateChecker\Vcs\API $api The API object that was used to fetch the info.
			 * @param string $ref The tag or branch that was used to fetch the info.
			 * @param YahnisElsts\PackageUpdateChecker\UpdateChecker $checker The update checker object calling this filter.
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

		protected function get_header_names() {
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
