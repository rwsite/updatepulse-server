<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

use Anyape\UpdatePulse\Package_Parser\Parser;

/**
 * This class represents the metadata from one specific WordPress plugin or theme.
 */
class Zip_Metadata_Parser {

	/**
	* @var int $cache_time  How long the package metadata should be cached in seconds.
	*                       Defaults to 1 week ( 7 * 24 * 60 * 60 ).
	*/
	public static $cache_time = 604800;

	/**
	* @var array Plugin PHP header mapping, i.e. which tags to add to the metadata under which array key
	*/
	protected $header_map = array(
		'Name'        => 'name',
		'Version'     => 'version',
		'PluginURI'   => 'homepage',
		'ThemeURI'    => 'homepage',
		'Homepage'    => 'homepage',
		'Author'      => 'author',
		'AuthorURI'   => 'author_homepage',
		'RequiresPHP' => 'requires_php',
		'Description' => 'description',
		'DetailsURI'  => 'details_url',
		'Depends'     => 'depends',
		'Provides'    => 'provides',
	);

	/**
	* @var array Plugin readme file mapping, i.e. which tags to add to the metadata
	*/
	protected $readme_map = array(
		'requires',
		'tested',
		'requires_php',
	);

	/**
	* @var array Package info as retrieved by the parser
	*/
	protected $package_info;

	/**
	* @var string Path to the Zip archive that contains the plugin or theme.
	*/
	protected $filename;

	/**
	* @var string Plugin or theme slug.
	*/
	protected $slug;

	/**
	* @var Cache object.
	*/
	protected $cache;

	/**
	* @var array Package metadata in a format suitable for the update checker.
	*/
	protected $metadata;


	/**
	* Get the metadata from a zip file.
	*
	* @param string $slug
	* @param string $filename
	* @param $cache
	*/
	public function __construct( $slug, $filename, $cache = null ) {
		$this->slug     = $slug;
		$this->filename = $filename;
		$this->cache    = $cache;

		$this->set_metadata();
	}

	/**
	* Get metadata.
	*
	* @return array
	*/
	public function get() {
		return $this->metadata;
	}

	/**
	* Load metadata information from a cache or create it.
	*
	* We'll try to load processed metadata from the cache first ( if available ), and if that
	* fails we'll extract plugin/theme details from the specified Zip file.
	*/
	protected function set_metadata() {
		$cache_key = $this->generate_cache_key();

		//Try the cache first.
		if ( isset( $this->cache ) ) {
			$this->metadata = $this->cache->get( $cache_key );
		}

		// Otherwise read out the metadata and create a cache
		if ( ! isset( $this->metadata ) || ! is_array( $this->metadata ) ) {
			$this->extract_metadata();

			//Update cache.
			if ( isset( $this->cache ) ) {
				$this->cache->set( $cache_key, $this->metadata, static::$cache_time );
			}
		}
	}

	/**
	* Extract plugin or theme headers and readme contents from a ZIP file and convert them
	* into a structure compatible with the custom update checker.
	*
	* See this page for an overview of the plugin metadata format:
	* @link https://spreadsheets.google.com/pub?key=0AqP80E74YcUWdEdETXZLcXhjd2w0cHMwX2U1eDlWTHc&authkey=CK7h9toK&hl=en&single=true&gid=0&output=html
	*
	* @throws Invalid_Package_Exception if the input file can't be parsed as a plugin or theme.
	*/
	protected function extract_metadata() {
		$this->package_info = Parser::parse_package( $this->filename, true );

		if ( is_array( $this->package_info ) && ! empty( $this->package_info ) ) {
			$this->set_info_from_header();
			$this->set_info_from_readme();
			$this->set_last_update_date();
			$this->set_info_from_assets();
			$this->set_slug();
			$this->set_type();
		} else {
			throw new Invalid_Package_Exception(
				sprintf(
					'The specified file %s does not contain a valid Generic package or WordPress plugin or theme.',
					esc_html( $this->filename )
				)
			);
		}
	}

	/**
	* Extract relevant metadata from the plugin/theme header information
	*/
	protected function set_info_from_header() {

		if ( isset( $this->package_info['header'] ) && ! empty( $this->package_info['header'] ) ) {
			$this->set_mapped_fields( $this->package_info['header'], $this->header_map );
			$this->set_theme_details_url();
		}
	}

	/**
	* Extract relevant metadata from the plugin/theme readme
	*/
	protected function set_info_from_readme() {

		if ( ! empty( $this->package_info['readme'] ) ) {
			$readme_map = array_combine( array_values( $this->readme_map ), $this->readme_map );

			$this->set_mapped_fields( $this->package_info['readme'], $readme_map );
			$this->set_readme_sections();
			$this->set_readme_upgrade_notice();
		}
	}

	/**
	* Extract selected metadata from the retrieved package info
	*
	* @see http://codex.wordpress.org/File_Header
	* @see https://wordpress.org/plugins/about/readme.txt
	*
	* @param array $input The package info sub-array to use to retrieve the info from
	* @param array $map   The key mapping for that sub-array where the key is the key as used in the
	*                     input array and the value is the key to use for the output array
	*/
	protected function set_mapped_fields( $input, $map ) {

		foreach ( $map as $field_key => $meta_key ) {

			if ( ! empty( $input[ $field_key ] ) ) {
				$this->metadata[ $meta_key ] = $input[ $field_key ];
			}
		}
	}

	/**
	* Determine the details url for themes
	*
	* Theme metadata should include a "details_url" that specifies the page to display
	* when the user clicks "View version x.y.z details". If the developer didn't provide
	* it by setting the "Details URI" header, we'll default to the theme homepage ( "Theme URI" ).
	*/
	protected function set_theme_details_url() {

		if (
			'theme' !== $this->package_info['type'] &&
			! isset( $this->metadata['details_url'] ) &&
			isset( $this->metadata['homepage'] ) ) {
			$this->metadata['details_url'] = $this->metadata['homepage'];
		}
	}

	/**
	* Extract the texual information sections from a readme file
	*
	* @see https://wordpress.org/plugins/about/readme.txt
	*/
	protected function set_readme_sections() {

		if (
			is_array( $this->package_info['readme']['sections'] ) &&
			array() !== $this->package_info['readme']['sections']
		) {

			foreach ( $this->package_info['readme']['sections'] as $section_name => $section_content ) {
				$section_content                             = '<div class="readme-section" data-name="'
					. $section_name
					. '">'
					. $section_content
					. '</div>';
				$section_name                                = str_replace( ' ', '_', strtolower( $section_name ) );
				$this->metadata['sections'][ $section_name ] = $section_content;
			}
		}
	}

	/**
	* Extract the upgrade notice for the current version from a readme file
	*
	* @see https://wordpress.org/plugins/about/readme.txt
	*/
	protected function set_readme_upgrade_notice() {

		//Check if we have an upgrade notice for this version
		if ( isset( $this->metadata['sections']['upgrade_notice'] ) && isset( $this->metadata['version'] ) ) {
			$regex = '@<h4>\s*'
				. preg_quote( $this->metadata['version'], '@' )
				. '\s*</h4>[^<>]*?<p>( .+? )</p>@i';

			if ( preg_match( $regex, $this->metadata['sections']['upgrade_notice'], $matches ) ) {
				$this->metadata['upgrade_notice'] = trim( wp_strip_all_tags( $matches[1] ) );
			}
		}
	}

	/**
	* Add last update date to the metadata
	*/
	protected function set_last_update_date() {

		if ( ! isset( $this->metadata['last_updated'] ) ) {
			$this->metadata['last_updated'] = gmdate( 'Y-m-d H:i:s', filemtime( $this->filename ) );
		}
	}

	protected function set_type() {
		$this->metadata['type'] = $this->package_info['type'];
	}

	protected function set_slug() {

		if ( 'plugin' === $this->package_info['type'] ) {
			$main_file = $this->package_info['plugin_file'];
		} elseif ( 'theme' === $this->package_info['type'] ) {
			$main_file = $this->package_info['stylesheet'];
		} elseif ( 'generic' === $this->package_info['type'] ) {
			$main_file = $this->package_info['generic_file'];
		}

		$this->metadata['slug'] = $main_file ? basename( dirname( strtolower( $main_file ) ) ) : '';
	}

	/**
	* Extract icons and banners info for plugins
	*/
	protected function set_info_from_assets() {

		if ( ! empty( $this->package_info['extra'] ) ) {
			$extra_meta = $this->package_info['extra'];

			if ( ! empty( $extra_meta['icons'] ) ) {
				$this->metadata['icons'] = $extra_meta['icons'];
			}

			if ( ! empty( $extra_meta['banners'] ) ) {
				$this->metadata['banners'] = $extra_meta['banners'];
			}

			if ( ! empty( $extra_meta['require_license'] ) ) {
				$this->metadata['require_license'] = (
					'yes' === $extra_meta['require_license'] ||
					'true' === $extra_meta['require_license'] ||
					1 === intval( $extra_meta['require_license'] )
				);
			}

			if ( ! empty( $extra_meta['licensed_with'] ) ) {
				$this->metadata['licensed_with'] = $extra_meta['licensed_with'];
			}
		}
	}

	/**
	* Generate the cache key ( cache filename ) for a file
	*/
	protected function generate_cache_key() {
		$cache_key = 'metadata-b64-' . $this->slug . '-';

		if ( file_exists( $this->filename ) ) {
			$cache_key .= md5( $this->filename . '|' . filesize( $this->filename ) . '|' . filemtime( $this->filename ) );
		}

		return apply_filters(
			'upserv_zip_metadata_parser_cache_key',
			$cache_key,
			$this->slug,
			$this->filename
		);
	}
}
