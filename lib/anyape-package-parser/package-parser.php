<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\UpdatePulse\Package_Parser;

use Anyape\Parsedown;
use ZipArchive as SystemZipArchive;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

/**
 * Package parser for WordPress plugins and themes.
 *
 * This class provides functionality to extract and analyze information from WordPress plugin and theme packages, or generic packages, in ZIP format.
 */
class Parser {

	/**
	* Extracts and parses metadata from a WordPress plugin or theme ZIP package.
	*
	* Analyzes the contents of a ZIP archive to determine if it contains a valid WordPress plugin, theme, or generic package, then extracts relevant metadata from header files and readme.txt (if present).
	*
	* The function returns an array with the following structure:
	* 'type'   - Package type: "plugin", "theme", or "generic"
	* 'header' - Package header information (varies by type)
	* 'readme' - Metadata extracted from readme.txt (if available)
	* 'pluginFile' - Path to the main plugin file (for plugins only)
	* 'stylesheet' - Path to style.css file (for themes only)
	* 'generic_file' - Path to updatepulse.json (for generic packages only)
	* 'extra' - Additional metadata like icons and banners (if available)
	*
	* @param string $package_filename The path to the ZIP package.
	* @param bool $apply_markdown Whether to transform markup used in readme.txt to HTML. Defaults to false.
	* @return array|bool Package information array or FALSE if invalid/unreadable.
	*/
	public static function parse_package( $package_filename, $apply_markdown = false ) {

		if ( ! file_exists( $package_filename ) || ! is_readable( $package_filename ) ) {
			return false;
		}

		$zip = ZipArchive::open( $package_filename );

		if ( false === $zip ) {
			return false;
		}

		// Find and parse the package file and (optionally) readme.txt
		$header        = null;
		$readme        = null;
		$plugin_file   = null;
		$stylesheet    = null;
		$generic_file  = null;
		$type          = null;
		$extra         = null;
		$entries       = $zip->list_entries();
		$slug          = str_replace( '.zip', '', basename( $package_filename ) );
		$count_entries = count( $entries );

		for (
			$file_index = 0;
			( $file_index < $count_entries ) && ( empty( $readme ) || empty( $header ) );
			$file_index++
		) {
			$info = $entries[ $file_index ];
			// Normalize filename: convert backslashes to slashes, remove leading slashes
			$file_name = trim( str_replace( '\\', '/', $info['name'] ), '/' );
			$file_name = ltrim( $file_name, '/' );

			// Add path traversal protection
			if ( false !== strpos( $file_name, '../' ) || false !== strpos( $file_name, '..\\' ) ) {
				// Log attempt and skip this file
				error_log( __METHOD__ . ' Potential path traversal attempt blocked for file: ' . $file_name ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

				continue;
			}

			$file_name_parts = explode( '.', $file_name );
			$extension       = strtolower( end( $file_name_parts ) );
			$depth           = substr_count( $file_name, '/' );

			// Skip files that are either empty, directories, or nested deeper than one level
			if ( ( $depth > 1 ) || $info['isFolder'] ) {
				continue;
			}

			// Check for and parse readme.txt file for plugins
			if ( empty( $readme ) && ( strtolower( basename( $file_name ) ) === 'readme.txt' ) ) {
				// Attempt to parse the readme content
				$readme = self::parse_readme( $zip->get_file_contents( $info ), $apply_markdown );
			}

			$file_contents = null;

			// Check if the provided file is for a theme
			if ( empty( $header ) && ( strtolower( basename( $file_name ) ) === 'style.css' ) ) {
				$file_contents = substr( $zip->get_file_contents( $info ), 0, 8 * 1024 );
				$header        = self::get_theme_headers( $file_contents );
				$generic_file  = null;

				if ( ! empty( $header ) ) {
					$stylesheet = $file_name;
					$type       = 'theme';
				}
			}

			// Check if the provided file is for a plugin
			if ( empty( $header ) && ( 'php' === $extension ) ) {
				$file_contents = substr( $zip->get_file_contents( $info ), 0, 8 * 1024 );
				$plugin_file   = $file_name;
				$generic_file  = null;
				$header        = self::get_plugin_headers( $file_contents );
				$type          = 'plugin';
			}

			// Check if the provided file is a generic package
			if ( empty( $header ) && ( 'json' === $extension ) && ( basename( $file_name ) === 'updatepulse.json' ) ) {
				$file_contents = substr( $zip->get_file_contents( $info ), 0, 8 * 1024 );
				$header        = self::get_generic_headers( $file_contents );
				$generic_file  = $file_name;
				$type          = 'generic';
			}

			if ( ! empty( $header ) && $file_contents ) {
				$extra = 'generic' === $type ?
					self::get_generic_extra_headers( $file_contents ) :
					self::get_extra_headers( $file_contents );
			}
		}

		if ( empty( $type ) ) {
			return false;
		}

		return compact( 'header', 'extra', 'readme', 'plugin_file', 'stylesheet', 'generic_file', 'type' );
	}

	/**
	* Extracts metadata from a WordPress plugin/theme readme.txt file.
	*
	* Parses the standardized WordPress readme.txt format to extract key information about the plugin or theme, including version requirements, descriptions, and documentation sections.
	*
	* The returned array includes:
	* 'name' - Plugin/theme name
	* 'contributors' - List of WordPress.org contributor usernames
	* 'donate' - Donation URL
	* 'tags' - Plugin tags/categories
	* 'requires' - Minimum WordPress version
	* 'requires_php' - Minimum PHP version
	* 'tested' - WordPress version tested up to
	* 'stable' - Stable release tag
	* 'short_description' - Brief plugin description
	* 'sections' - Content sections (FAQ, installation, etc.)
	*
	* @param string $readme_txt_contents The contents of a readme.txt file.
	* @param bool $apply_markdown Whether to convert Markdown to HTML. Defaults to false.
	* @return array|null Parsed readme data or NULL if invalid format.
	*/
	public static function parse_readme( $readme_txt_contents, $apply_markdown = false ) {
		$readme_txt_contents = trim( $readme_txt_contents, " \t\n\r" );
		$readme              = array(
			'name'              => '',
			'contributors'      => array(),
			'donate'            => '',
			'tags'              => array(),
			'requires'          => '',
			'requires_php'      => '',
			'tested'            => '',
			'stable'            => '',
			'short_description' => '',
			'sections'          => array(),
		);

		// Do a line-by-line parse of the readme.txt file
		$lines = explode( "\n", $readme_txt_contents );

		// Get the name of the plugin
		if ( preg_match( '@===\s*( .+? )\s*===@', array_shift( $lines ), $matches ) ) {
			$readme['name'] = $matches[1];
		} else {
			return null;
		}

		// Set up a map of header fields to their corresponding keys in the readme array
		$headers    = array();
		$header_map = array(
			'Contributors'      => 'contributors',
			'Donate link'       => 'donate',
			'Tags'              => 'tags',
			'Requires at least' => 'requires',
			'Tested up to'      => 'tested',
			'Requires PHP'      => 'requires_php',
			'Stable tag'        => 'stable',
		);

		do {
			$pieces = explode( ':', array_shift( $lines ), 2 );

			if ( array_key_exists( $pieces[0], $header_map ) ) {

				if ( isset( $pieces[1] ) ) {
					$headers[ $header_map[ $pieces[0] ] ] = (string) trim( $pieces[1] );
				} else {
					$headers[ $header_map[ $pieces[0] ] ] = '';
				}
			}
		} while ( trim( $pieces[0] ) !== '' );

		// Convert comma-separated contributors list into an array
		if ( ! empty( $headers['contributors'] ) ) {
			$headers['contributors'] = array_map( 'trim', explode( ',', $headers['contributors'] ) );
		}

		// Convert comma-separated tags list into an array
		if ( ! empty( $headers['tags'] ) ) {
			$headers['tags'] = array_map( 'trim', explode( ',', $headers['tags'] ) );
		}

		$readme = array_merge( $readme, $headers );
		// Extract the short description from the next line
		$readme['short_description'] = array_shift( $lines );

		// Parse remaining content into sections (e.g., "== Description ==", "== Installation ==", etc.)
		$sections        = array();
		$content_buffer  = array();
		$current_section = '';

		foreach ( $lines as $line ) {

			// Check if there is a section header
			if ( preg_match( '@^\s*==\s+(.+?)\s+==\s*$@m', $line, $matches ) ) {

				// Flush the content buffer for the previous section, if any
				if ( ! empty( $current_section ) ) {
					$section_content              = trim( implode( "\n", $content_buffer ) );
					$sections[ $current_section ] = $section_content;
				}

				// Read a new section
				$current_section = $matches[1];
				$content_buffer  = array();
			} else {
				// Buffer all section content
				$content_buffer[] = $line;
			}
		}

		// Flush the buffer for the last section
		if ( ! empty( $current_section ) ) {
			$sections[ $current_section ] = trim( implode( "\n", $content_buffer ) );
		}

		// Apply Markdown to sections
		if ( $apply_markdown ) {
			$sections = array_map( __CLASS__ . '::apply_markdown', $sections );
		}

		$readme['sections'] = $sections;

		return $readme;
	}

	/**
	* Converts Markdown syntax to HTML format.
	*
	* This method processes text with Markdown formatting and returns HTML content.
	* It handles WordPress-specific readme.txt formatting conventions, including custom header syntax like "= H4 headers =".
	*
	* @param string $text Text content with Markdown formatting
	* @return string HTML-formatted content
	*/
	private static function apply_markdown( $text ) {
		// The WP standard for readme files uses some custom markup, like "= H4 headers ="
		$text = preg_replace( '@^\s*=\s*( .+? )\s*=\s*$@m', "\n####$1####\n", $text );

		return Parsedown::instance()->text( $text );
	}

	/**
	* Extracts plugin header metadata from PHP file content.
	*
	* Parses a plugin file to extract standard WordPress plugin headers like name, version, author information, and other metadata. This mimics WordPress's get_plugin_data() function to handle plugin header extraction.
	*
	* The returned array includes:
	* 'Name', 'Title', 'Description', 'Author', 'AuthorURI', 'Version', 'PluginURI', 'TextDomain', 'DomainPath', 'Network', 'Depends', 'Provides', 'RequiresPHP', and others.
	*
	* @param string $file_contents Contents of the plugin file
	* @return array|null Plugin metadata or NULL if no valid plugin header found
	*/
	public static function get_plugin_headers( $file_contents ) {
		//[Internal name => Name used in the plugin file]
		$headers = self::get_file_headers(
			$file_contents,
			array(
				'Name'        => 'Plugin Name',
				'PluginURI'   => 'Plugin URI',
				'Version'     => 'Version',
				'Description' => 'Description',
				'Author'      => 'Author',
				'AuthorURI'   => 'Author URI',
				'TextDomain'  => 'Text Domain',
				'DomainPath'  => 'Domain Path',
				'Network'     => 'Network',
				'Depends'     => 'Depends',
				'Provides'    => 'Provides',
				'RequiresPHP' => 'Requires PHP',
				//Site Wide Only is deprecated in favor of Network.
				'_sitewide'   => 'Site Wide Only',
			)
		);

		if ( empty( $headers['Network'] ) && ! empty( $headers['_sitewide'] ) ) {
			$headers['Network'] = $headers['_sitewide'];
		}

		unset( $headers['_sitewide'] );

		$headers['Network'] = ( strtolower( $headers['Network'] ) === 'true' );

		// For backward compatibility, by default, Title is the same as Name.
		$headers['Title'] = $headers['Name'];

		// Comma-separated list. Convert it to an array.
		if ( ! empty( $headers['Depends'] ) ) {
			$headers['Depends'] = array_map( 'trim', explode( ',', $headers['Depends'] ) );
		}

		// Comma-separated list. Convert it to an array.
		if ( ! empty( $headers['Provides'] ) ) {
			$headers['Provides'] = array_map( 'trim', explode( ',', $headers['Provides'] ) );
		}

		// If no name is found, return null - not a plugin.
		if ( empty( $headers['Name'] ) ) {
			return null;
		}

		return $headers;
	}

	/**
	* Extracts theme metadata from style.css file content.
	*
	* Analyzes a WordPress theme's style.css file to extract standardized theme headers that provide information about the theme, including name, version, author details, and theme dependencies.
	*
	* The returned array includes: 'Name', 'Description', 'Author', 'AuthorURI', 'Version', 'ThemeURI', 'Template' (parent theme), 'Tags', 'TextDomain', 'DomainPath', and more.
	*
	* @param string $file_contents Contents of the theme stylesheet
	* @return array|null Theme metadata or NULL if no valid theme header found
	*/
	public static function get_theme_headers( $file_contents ) {
		//[Internal name => Name used in the theme file]
		$headers = self::get_file_headers(
			$file_contents,
			array(
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
				'DetailsURI'  => 'Details URI',
			)
		);

		$headers['Tags'] = array_filter( array_map( 'trim', explode( ',', wp_strip_all_tags( $headers['Tags'] ) ) ) );

		// If no name is found, return null - not a theme.
		if ( empty( $headers['Name'] ) ) {
			return null;
		}

		return $headers;
	}

	/**
	* Extracts generic package metadata from updatepulse.json file.
	*
	* Parses a JSON file containing package metadata for non-standard WordPress packages. This allows UpdatePulse to manage generic software packages alongside plugins and themes.
	*
	* The function extracts standard package information fields:
	* 'Name', 'Version', 'Homepage', 'Author', 'AuthorURI', 'Description'
	*
	* @param string $file_contents Contents of the updatepulse.json file
	* @return array Extracted package metadata
	*/
	public static function get_generic_headers( $file_contents ) {
		$decoded_contents = json_decode( $file_contents, true );
		$generic_headers  = array();

		if ( isset( $decoded_contents['packageData'] ) && ! empty( $decoded_contents['packageData'] ) ) {
			$package_data = $decoded_contents['packageData'];
			$valid_keys   = array(
				'Name',
				'Version',
				'Homepage',
				'Author',
				'AuthorURI',
				'Description',
			);

			foreach ( $valid_keys as $key ) {

				if ( ! empty( $package_data[ $key ] ) ) {
					$generic_headers[ $key ] = $package_data[ $key ];
				}
			}
		}

		return $generic_headers;
	}

	/**
	* Extracts additional metadata from a generic package's JSON file.
	*
	* Parses the updatepulse.json file to retrieve supplementary informationlike icons, banners, and licensing requirements for generic packages.
	*
	* The returned array may contain:
	* 'icons' - Package icons in different resolutions
	* 'banners' - Banner images in high/low resolutions
	* 'require_license' - Whether the package requires license validation
	* 'licensed_with' - Associated licensing system or provider
	*
	* @param string $file_contents Contents of the updatepulse.json file
	* @return array Additional package metadata
	*/
	public static function get_generic_extra_headers( $file_contents ) {
		$decoded_contents = json_decode( $file_contents, true );
		$generic_extra    = array();

		if ( isset( $decoded_contents['packageData'] ) && ! empty( $decoded_contents['packageData'] ) ) {
			$package_data       = $decoded_contents['packageData'];
			$extra_header_names = array(
				'Icon1x'         => 'Icon1x',
				'Icon2x'         => 'Icon2x',
				'BannerHigh'     => 'BannerHigh',
				'BannerLow'      => 'BannerLow',
				'RequireLicense' => 'require_license',
				'LicensedWith'   => 'licensed_with',
			);

			foreach ( $extra_header_names as $name => $key ) {

				if ( ! empty( $package_data[ $name ] ) ) {

					if ( 0 === strpos( $name, 'Banner' ) ) {
						$generic_extra['banners']         = (
								isset( $generic_extra['banners'] ) &&
								is_array( $generic_extra['banners'] )
							) ?
							$generic_extra['banners'] :
							array();
						$idx                              = strtolower( str_replace( 'Banner', '', $name ) );
						$generic_extra['banners'][ $idx ] = $package_data[ $name ];
					} elseif ( 0 === strpos( $name, 'Icon' ) ) {
						$generic_extra['icons']         = (
								isset( $generic_extra['icons'] ) &&
								is_array( $generic_extra['icons'] )
							) ?
							$generic_extra['icons'] :
							array();
						$idx                            = str_replace( 'Icon', '', $name );
						$generic_extra['icons'][ $idx ] = $package_data[ $name ];
					} else {
						$generic_extra[ $key ] = $package_data[ $name ];
					}
				}
			}
		}

		return $generic_extra;
	}

	/**
	* Extracts visual assets and licensing information from package files.
	*
	* Searches plugin and theme files for special headers that define supplementary assets like icons and banners, as well as licensing requirements.
	*
	* The returned array may include:
	* 'icons' - Package icon URLs in different resolutions
	* 'banners' - Banner image URLs in high/low resolutions
	* 'require_license' - Whether the package requires license validation
	* 'licensed_with' - Associated licensing system or provider
	*
	* @param string $file_contents Contents of a plugin or theme file
	* @return array|null Supplementary metadata or NULL if none found
	*/
	public static function get_extra_headers( $file_contents ) {
		//[Internal name => Name used in the package file]
		$extra_header_names = array(
			'Icon1x'         => 'Icon1x',
			'Icon2x'         => 'Icon2x',
			'BannerHigh'     => 'BannerHigh',
			'BannerLow'      => 'BannerLow',
			'RequireLicense' => 'Require License',
			'LicensedWith'   => 'Licensed With',
		);
		$headers            = self::get_file_headers( $file_contents, $extra_header_names );
		$extra_headers      = array();

		if ( ! empty( $headers['RequireLicense'] ) ) {
			$extra_headers['require_license'] = ! in_array(
				$headers['RequireLicense'],
				array( 'false', 'no', '0', 'off', 0 ),
				true
			);
		} else {
			$extra_headers['require_license'] = false;
		}

		if ( ! empty( $headers['LicensedWith'] ) ) {
			$extra_headers['licensed_with'] = $headers['LicensedWith'];
		}

		if ( ! empty( $headers['Icon1x'] ) || ! empty( $headers['Icon2x'] ) ) {
			$extra_headers['icons'] = array();

			if ( ! empty( $headers['Icon1x'] ) ) {
				$extra_headers['icons']['1x'] = $headers['Icon1x'];
			}

			if ( ! empty( $headers['Icon2x'] ) ) {
				$extra_headers['icons']['2x'] = $headers['Icon2x'];
			}
		}

		if ( ! empty( $headers['BannerLow'] ) || ! empty( $headers['BannerHigh'] ) ) {
			$extra_headers['banners'] = array();

			if ( ! empty( $headers['BannerLow'] ) ) {
				$extra_headers['banners']['low'] = $headers['BannerLow'];
			}

			if ( ! empty( $headers['BannerHigh'] ) ) {
				$extra_headers['banners']['high'] = $headers['BannerHigh'];
			}
		}

		if ( empty( $extra_headers ) ) {
			return null;
		}

		return $extra_headers;
	}

	/**
	* Extracts metadata headers from file contents.
	*
	* A low-level utility function that searches for formatted header comments in file content. It supports the standard WordPress header format used for plugins, themes, and other metadata files.
	*
	* Each header must appear on its own line in the format:
	* "Header Name: Header Value"
	*
	* @param string $file_contents File content to search for headers
	* @param array $header_map Map of internal header names to their file representation
	* @return array Extracted header values indexed by internal names
	*/
	public static function get_file_headers( $file_contents, $header_map ) {
		$headers = array();
		// Support systems that use CR as a line ending.
		$file_contents = str_replace( "\r", "\n", $file_contents );

		foreach ( $header_map as $field => $pretty_name ) {
			$found = preg_match(
				'/^[ \t\/*#@]*'
					. preg_quote( $pretty_name, '/' )
					. ':(.*)$/mi',
				$file_contents,
				$matches
			);

			if ( ( $found > 0 ) && ! empty( $matches[1] ) ) {
				// Strip comment markers and closing PHP tags.
				$value             = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $matches[1] ) );
				$headers[ $field ] = $value;
			} else {
				$headers[ $field ] = '';
			}
		}

		return $headers;
	}
}

/**
 * Wrapper class for PHP's built-in ZipArchive.
 *
 * Provides a simplified interface for working with ZIP archives,
 * specifically tailored for parsing WordPress package files.
 */
class ZipArchive {
	/**
	* @var SystemZipArchive
	*/
	protected $archive;

	protected function __construct( $zip_archive ) {
		$this->archive = $zip_archive;
	}

	/**
	* Opens a ZIP archive file for reading.
	*
	* Creates and initializes a ZipArchive instance from a file path.
	* The method handles the low-level details of opening the archive.
	*
	* @param string $zip_file_name Path to the ZIP archive file
	* @return bool|ZipArchive ZipArchive instance or FALSE on failure
	*/
	public static function open( $zip_file_name ) {
		$zip = new SystemZipArchive();

		if ( $zip->open( $zip_file_name ) !== true ) {
			return false;
		}

		return new self( $zip );
	}

	/**
	* Lists all entries in the ZIP archive.
	*
	* Provides information about each file and folder in the archive, including name, size, and whether it's a folder.
	*
	* @return array List of entry information arrays
	*/
	public function list_entries() {
		$list = array();
		$zip  = $this->archive;

		for ( $index = 0; $index < $zip->numFiles; $index++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$info = $zip->statIndex( $index );

			if ( is_array( $info ) ) {
				$list[] = array(
					'name'     => $info['name'],
					'size'     => $info['size'],
					'isFolder' => ( 0 === $info['size'] ),
					'index'    => $index,
				);
			}
		}

		return $list;
	}

	/**
	* Retrieves the contents of a file within the ZIP archive.
	*
	* Extracts and returns the contents of a specific file identified by its information array (typically from list_entries).
	*
	* @param array $file_info File information containing 'index' key
	* @return string File contents
	*/
	public function get_file_contents( $file_info ) {
		return $this->archive->getFromIndex( $file_info['index'] );
	}
}
