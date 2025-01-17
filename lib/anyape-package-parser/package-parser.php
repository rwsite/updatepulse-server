<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName

namespace Anyape\UpdatePulse\Package_Parser;

use Parsedown;
use PclZip;
use ZipArchive as SystemZipArchive;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

class Parser {
	/**
	* Extract headers and readme.txt data from a ZIP archive that contains a plugin or theme.
	*
	* Returns an associative array with these keys:
	* 'type'   - Detected package type. This can be either "plugin" or "theme".
	* 'header' - An array of plugin or theme headers. See get_plugin_data() or WP_Theme for details.
	* 'readme' - An array of metadata extracted from readme.txt. @see self::parseReadme()
	* 'pluginFile' - The name of the PHP file where the plugin headers were found relative to the root directory of the ZIP archive.
	* 'stylesheet' - The relative path to the style.css file that contains theme headers, if any.
	*
	* The 'readme' key will only be present if the input archive contains a readme.txt file
	* formatted according to WordPress.org readme standards. Similarly, 'pluginFile' and
	* 'stylesheet' will only be present if the archive contains a plugin or a theme, respectively.
	*
	* @param string $package_filename The path to the ZIP package.
	* @param bool $apply_markdown Whether to transform markup used in readme.txt to HTML. Defaults to false.
	* @return array|bool Either an associative array or FALSE if the input file is not a valid ZIP archive or doesn't contain a WP plugin or theme.
	*/
	public static function parse_package( $package_filename, $apply_markdown = false ) {

		if ( ! file_exists( $package_filename ) || ! is_readable( $package_filename ) ) {
			return false;
		}

		$zip = Archive::open( $package_filename );

		if ( false === $zip ) {
			return false;
		}

		//Find and parse the plugin or theme file and ( optionally ) readme.txt.
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
			//Normalize filename: convert backslashes to slashes, remove leading slashes.
			$file_name       = trim( str_replace( '\\', '/', $info['name'] ), '/' );
			$file_name       = ltrim( $file_name, '/' );
			$file_name_parts = explode( '.', $file_name );
			$extension       = strtolower( end( $file_name_parts ) );
			$depth           = substr_count( $file_name, '/' );

			// Skip empty files, directories and everything that's more than 1 sub-directory deep.
			if ( ( $depth > 1 ) || $info['isFolder'] ) {
				continue;
			}

			// readme.txt ( for plugins )?
			if ( empty( $readme ) && ( strtolower( basename( $file_name ) ) === 'readme.txt' ) ) {
				//Try to parse the readme.
				$readme = self::parse_readme( $zip->get_file_contents( $info ), $apply_markdown );
			}

			$file_contents = null;

			// Theme stylesheet?
			if ( empty( $header ) && ( strtolower( basename( $file_name ) ) === 'style.css' ) ) {
				$file_contents = substr( $zip->get_file_contents( $info ), 0, 8 * 1024 );
				$header        = self::get_theme_headers( $file_contents );
				$generic_file  = null;

				if ( ! empty( $header ) ) {
					$stylesheet = $file_name;
					$type       = 'theme';
				}
			}

			// Main plugin file?
			if ( empty( $header ) && ( 'php' === $extension ) ) {
				$file_contents = substr( $zip->get_file_contents( $info ), 0, 8 * 1024 );
				$plugin_file   = $file_name;
				$generic_file  = null;
				$header        = self::get_plugin_headers( $file_contents );
				$type          = 'plugin';
			}

			// Generic info file?
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
	* Parse a plugin's readme.txt to extract various plugin metadata.
	*
	* Returns an array with the following fields:
	* 'name' - Name of the plugin.
	* 'contributors' - An array of wordpress.org usernames.
	* 'donate' - The plugin's donation link.
	* 'tags' - An array of the plugin's tags.
	* 'requires' - The minimum version of WordPress that the plugin will run on.
	* 'tested' - The latest version of WordPress that the plugin has been tested on.
	* 'stable' - The SVN tag of the latest stable release, or 'trunk'.
	* 'short_description' - The plugin's "short description".
	* 'sections' - An associative array of sections present in the readme.txt.
	*               Case and formatting of section headers will be preserved.
	*
	* Be warned that this function does *not* perfectly emulate the way that WordPress.org
	* parses plugin readme's. In particular, it may mangle certain HTML markup that wp.org
	* handles correctly.
	*
	* @see http://wordpress.org/extend/plugins/about/readme.txt
	*
	* @param string $readme_txt_contents The contents of a plugin's readme.txt file.
	* @param bool $apply_markdown Whether to transform Markdown used in readme.txt sections to HTML. Defaults to false.
	* @return array|null Associative array, or NULL if the input isn't a valid readme.txt file.
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

		//The readme.txt header has a fairly fixed structure, so we can parse it line-by-line
		$lines = explode( "\n", $readme_txt_contents );

		//Plugin name is at the very top, e.g. === My Plugin ===
		if ( preg_match( '@===\s*( .+? )\s*===@', array_shift( $lines ), $matches ) ) {
			$readme['name'] = $matches[1];
		} else {
			return null;
		}

		//Then there's a bunch of meta fields formatted as "Field: value"
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

		do { //Parse each readme.txt header
			$pieces = explode( ':', array_shift( $lines ), 2 );

			if ( array_key_exists( $pieces[0], $header_map ) ) {

				if ( isset( $pieces[1] ) ) {
					$headers[ $header_map[ $pieces[0] ] ] = trim( $pieces[1] );
				} else {
					$headers[ $header_map[ $pieces[0] ] ] = '';
				}
			}
		} while ( trim( $pieces[0] ) !== '' ); //Until an empty line is encountered

		//"Contributors" is a comma-separated list. Convert it to an array.
		if ( ! empty( $headers['contributors'] ) ) {
			$headers['contributors'] = array_map( 'trim', explode( ',', $headers['contributors'] ) );
		}

		//Likewise for "Tags"
		if ( ! empty( $headers['tags'] ) ) {
			$headers['tags'] = array_map( 'trim', explode( ',', $headers['tags'] ) );
		}

		$readme = array_merge( $readme, $headers );
		//After the headers comes the short description
		$readme['short_description'] = array_shift( $lines );

		//Finally, a valid readme.txt also contains one or more "sections" identified by "== Section Name =="
		$sections        = array();
		$content_buffer  = array();
		$current_section = '';

		foreach ( $lines as $line ) {

			//Is this a section header?
			if ( preg_match( '@^\s*==\s+( .+? )\s+==\s*$@m', $line, $matches ) ) {

				//Flush the content buffer for the previous section, if any
				if ( ! empty( $current_section ) ) {
					$section_content              = trim( implode( "\n", $content_buffer ) );
					$sections[ $current_section ] = $section_content;
				}

				//Start reading a new section
				$current_section = $matches[1];
				$content_buffer  = array();
			} else {
				//Buffer all section content
				$content_buffer[] = $line;
			}
		}
		//Flush the buffer for the last section
		if ( ! empty( $current_section ) ) {
			$sections[ $current_section ] = trim( implode( "\n", $content_buffer ) );
		}

		//Apply Markdown to sections
		if ( $apply_markdown ) {
			$sections = array_map( __CLASS__ . '::apply_markdown', $sections );
		}

		$readme['sections'] = $sections;

		return $readme;
	}

	/**
	* Transform Markdown markup to HTML.
	*
	* Tries ( in vain ) to emulate the transformation that WordPress.org applies to readme.txt files.
	*
	* @param string $text
	* @return string
	*/
	private static function apply_markdown( $text ) {
		//The WP standard for readme files uses some custom markup, like "= H4 headers ="
		$text = preg_replace( '@^\s*=\s*( .+? )\s*=\s*$@m', "\n####$1####\n", $text );

		return Parsedown::instance()->text( $text );
	}

	/**
	* Parse the plugin contents to retrieve plugin's metadata headers.
	*
	* Adapted from the get_plugin_data() function used by WordPress.
	* Returns an array that contains the following:
	* 'Name' - Name of the plugin.
	* 'Title' - Title of the plugin and the link to the plugin's web site.
	* 'Description' - Description of what the plugin does and/or notes from the author.
	* 'Author' - The author's name.
	* 'AuthorURI' - The author's web site address.
	* 'Version' - The plugin version number.
	* 'PluginURI' - Plugin web site address.
	* 'TextDomain' - Plugin's text domain for localization.
	* 'DomainPath' - Plugin's relative directory path to .mo files.
	* 'Network' - Boolean. Whether the plugin can only be activated network wide.
	*
	* If the input string doesn't appear to contain a valid plugin header, the function
	* will return NULL.
	*
	* @param string $file_contents Contents of the plugin file
	* @return array|null See above for description.
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

		//Site Wide Only is the old header for Network.
		if ( empty( $headers['Network'] ) && ! empty( $headers['_sitewide'] ) ) {
			$headers['Network'] = $headers['_sitewide'];
		}

		unset( $headers['_sitewide'] );

		$headers['Network'] = ( strtolower( $headers['Network'] ) === 'true' );

		//For backward compatibility by default Title is the same as Name.
		$headers['Title'] = $headers['Name'];

		//"Depends" is a comma-separated list. Convert it to an array.
		if ( ! empty( $headers['Depends'] ) ) {
			$headers['Depends'] = array_map( 'trim', explode( ',', $headers['Depends'] ) );
		}

		//Same for "Provides"
		if ( ! empty( $headers['Provides'] ) ) {
			$headers['Provides'] = array_map( 'trim', explode( ',', $headers['Provides'] ) );
		}

		//If it doesn't have a name, it's probably not a plugin.
		if ( empty( $headers['Name'] ) ) {
			return null;
		}

		return $headers;
	}

	/**
	* Parse the theme stylesheet to retrieve its metadata headers.
	*
	* Adapted from the get_theme_data() function and the WP_Theme class in WordPress.
	* Returns an array that contains the following:
	* 'Name' - Name of the theme.
	* 'Description' - Theme description.
	* 'Author' - The author's name
	* 'AuthorURI' - The authors web site address.
	* 'Version' - The theme version number.
	* 'ThemeURI' - Theme web site address.
	* 'Template' - The slug of the parent theme. Only applies to child themes.
	* 'Status' - Unknown. Included for completeness.
	* 'Tags' - An array of tags.
	* 'TextDomain' - Theme's text domain for localization.
	* 'DomainPath' - Theme's relative directory path to .mo files.
	*
	* If the input string doesn't appear to contain a valid theme header, the function
	* will return NULL.
	*
	* @param string $file_contents Contents of the theme stylesheet.
	* @return array|null See above for description.
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

		//If it doesn't have a name, it's probably not a valid theme.
		if ( empty( $headers['Name'] ) ) {
			return null;
		}

		return $headers;
	}

	/**
	* Parse the generic package's headers from updatepulse.json file.
	* Returns an array that may contain the following:
	* 'Name'
	* 'Version'
	* 'Homepage'
	* 'Author'
	* 'AuthorURI'
	* 'Description'
	* @param string $file_contents Contents of the package file
	* @return array See above for description.
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
	* Parse the generic package's extra headers from updatepulse.json file.
	* Returns an array that may contain the following:
	* 'Icon1x'
	* 'Icon2x'
	* 'BannerHigh'
	* 'BannerLow'
	* 'RequireLicense'
	* 'LicensedWith'
	* @param string $file_contents Contents of the package file
	* @return array See above for description.
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
	* Parse the package contents to retrieve icons and banners information.
	*
	* Returns an array that may contain the following:
	* 'icons':
	* 'Icon1x'
	* 'Icon2x'
	* 'banners':
	* 'BannerHigh'
	* 'BannerLow'
	* 'Require License'
	* 'Licensed With'
	*
	* If the data is not found, the function
	* will return NULL.
	*
	* @param string $fileContents Contents of the package file
	* @return array|null See above for description.
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

		$headers       = self::get_file_headers( $file_contents, $extra_header_names );
		$extra_headers = array();

		if ( ! empty( $headers['RequireLicense'] ) ) {
			$extra_headers['require_license'] = $headers['RequireLicense'];
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
	* Parse the file contents to retrieve its metadata.
	*
	* Searches for metadata for a file, such as a plugin or theme.  Each piece of
	* metadata must be on its own line. For a field spanning multiple lines, it
	* must not have any newlines or only parts of it will be displayed.
	*
	* @param string $file_contents File contents. Can be safely truncated to 8kiB as that's all WP itself scans.
	* @param array $header_map The list of headers to search for in the file.
	* @return array
	*/
	public static function get_file_headers( $file_contents, $header_map ) {
		$headers = array();

		//Support systems that use CR as a line ending.
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
				//Strip comment markers and closing PHP tags.
				$value             = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $matches[1] ) );
				$headers[ $field ] = $value;
			} else {
				$headers[ $field ] = '';
			}
		}

		return $headers;
	}
}

abstract class Archive {
	/**
	* Open a Zip archive.
	*
	* @param string $zip_file_name
	* @return bool|Archive
	*/
	public static function open( $zip_file_name ) {

		if ( class_exists( '\\ZipArchive', false ) ) {
			$zip = new ZipArchive( $zip_file_name );

			return $zip->open( $zip_file_name );
		} else {
			return PclZipArchive::open( $zip_file_name );
		}
	}

	/**
	* Get the list of files and directories in the archive.
	*
	* @return array
	*/
	abstract public function list_entries();

	/**
	* Get the contents of a specific file.
	*
	* @param array $file
	* @return string|false
	*/
	abstract public function get_file_contents( $file );
}

class ZipArchive extends Archive {
	/**
	* @var SystemZipArchive
	*/
	protected $archive;

	protected function __construct( $zip_archive ) {
		$this->archive = $zip_archive;
	}

	public static function open( $zip_file_name ) {
		$zip = new SystemZipArchive();

		if ( $zip->open( $zip_file_name ) !== true ) {
			return false;
		}
		return new self( $zip );
	}

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

	public function get_file_contents( $file_info ) {
		return $this->archive->getFromIndex( $file_info['index'] );
	}
}

class PclZipArchive extends Archive {
	/**
	* @var PclZip
	*/
	protected $archive;

	protected function __construct( $zip_file_name ) {
		$this->archive = new PclZip( $zip_file_name );
	}

	public static function open( $zip_file_name ) {

		if ( ! class_exists( 'PclZip', false ) ) {
			return null;
		}

		return new self( $zip_file_name );
	}

	public function list_entries() {
		$contents = $this->archive->listContent();

		if ( 0 === $contents ) {
			return array();
		}

		$list = array();

		foreach ( $contents as $info ) {
			$list[] = array(
				'name'     => $info['filename'],
				'size'     => $info['size'],
				'isFolder' => $info['folder'],
				'index'    => $info['index'],
			);
		}

		return $list;
	}

	public function get_file_contents( $file_info ) {
		$result = $this->archive->extract( PCLZIP_OPT_BY_INDEX, $file_info['index'], PCLZIP_OPT_EXTRACT_AS_STRING );

		if ( ( 0 === $result ) || ( ! isset( $result[0], $result[0]['content'] ) ) ) {
			return false;
		}

		return $result[0]['content'];
	}
}
