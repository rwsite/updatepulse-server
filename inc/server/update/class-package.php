<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * This class represents the collection of files and metadata that make up
 * a WordPress plugin or theme, or a generic software package.
 *
 */
class Package {

	/** @var string Path to the Zip archive that contains the package. */
	protected $filename;

	/** @var array Package metadata in a format suitable for the update checker. */
	protected $metadata = array();

	/** @var string Package slug. */
	public $slug;

	/**
	 * Create a new package.
	 *
	 * In most cases you will probably want to use self::fromArchive($package) instead
	 * of instantiating this class directly. Still, you can do it if you want to, for example,
	 * load package metadata from the database instead of extracting it from a Zip file.
	 *
	 * @param string $slug
	 * @param string $filename
	 * @param array $metadata
	 */
	public function __construct( $slug, $filename = null, $metadata = array() ) {
		$this->slug     = $slug;
		$this->filename = $filename;
		$this->metadata = $metadata;
	}

	/**
	 * Get the full file path of this package.
	 *
	 * @return string
	 */
	public function get_filename() {
		return $this->filename;
	}

	/**
	 * Get package metadata.
	 *
	 * @see self::extractMetadata()
	 * @return array
	 */
	public function get_metadata() {
		return array_merge( $this->metadata, array( 'slug' => $this->slug ) );
	}

	/**
	 * Load package information.
	 *
	 * @param string $filename Path to a Zip archive that contains a package.
	 * @param string $slug Optional package slug. Will be detected automatically.
	 * @param Cache $cache
	 * @return Package
	 */
	public static function from_archive( $filename, $slug = null, Cache $cache = null ) {
		$meta_obj = new Zip_Metadata_Parser( $slug, $filename, $cache );
		$metadata = $meta_obj->get();

		if ( null === $slug && isset( $metadata['slug'] ) ) {
			$slug = $metadata['slug'];
		}

		return new self( $slug, $filename, $metadata );
	}

	/**
	 * Get the size of the package (in bytes).
	 *
	 * @return int
	 */
	public function get_file_size() {
		return filesize( $this->filename );
	}

	/**
	 * Get the Unix timestamp of the last time this package was modified.
	 *
	 * @return int
	 */
	public function get_last_modified() {
		return filemtime( $this->filename );
	}
}
