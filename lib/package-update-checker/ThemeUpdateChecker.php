<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\PackageUpdateChecker;

if ( ! class_exists( ThemeUpdateChecker::class, false ) ) :

	class ThemeUpdateChecker extends UpdateChecker implements Vcs\BaseChecker {
		public $theme_absolute_path = '';

		public function __construct( $api, $slug, $container ) {
			$this->api                 = $api;
			$this->theme_absolute_path = trailingslashit( $container ) . $slug;
			$this->debug_mode          = (bool) ( constant( 'WP_DEBUG' ) );
			$this->directory_name      = basename( dirname( $this->theme_absolute_path ) );
			$this->slug                = ! empty( $slug ) ? $slug : $this->directory_name;

			$this->api->set_slug( $this->slug );
		}

		public function request_info() {
			$this->api->set_local_directory( trailingslashit( $this->theme_absolute_path ) );

			//Figure out which reference (tag or branch) we'll use to get the latest version of the theme.
			$update_source = $this->api->choose_reference( $this->branch );

			if ( $update_source ) {
				$ref = $update_source->name;
			} else {
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

			if ( is_array( $info ) && isset( $info['abort_request'] ) && $info['abort_request'] ) {
				return $info;
			}

			//Get headers from the main stylesheet in this branch/tag. Its "Version" header and other metadata
			//are what the WordPress install will actually see after upgrading, so they take precedence over releases/tags.
			$file = $this->api->get_remote_file( 'style.css', $ref );

			if ( ! empty( $file ) ) {
				$remote_header   = $this->get_file_header( $file );
				$info['version'] = empty( $remote_header['Version'] ) ? $update_source->version : $remote_header['Version'];
			}

			$info['download_url'] = $this->api->sign_download_url( $update_source->download_url );
			$info['type']         = 'Theme';

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

		public function set_branch( $branch ) {
			$this->branch = $branch;

			return $this;
		}

		public function set_authentication( $credentials ) {
			$this->api->set_authentication( $credentials );

			return $this;
		}

		public function get_vcs_api() {
			return $this->api;
		}
	}

endif;
