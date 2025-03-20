<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Simple request class for the update server.
 *
 * Handles incoming update requests, parsing parameters and headers.
 */
class Request {

	/**
	 * Query parameters
	 *
	 * @var array
	 * @since 1.0.0
	 */
	public $query = array();
	/**
	 * Client's IP address
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $client_ip;
	/**
	 * The HTTP method
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $http_method;
	/**
	 * The name of the current action
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $action;
	/**
	 * Package slug from the current request
	 *
	 * @var string
	 * @since 1.0.0
	 */
	public $slug;
	/**
	 * The package that matches the current slug
	 *
	 * @var Package|null
	 * @since 1.0.0
	 */
	public $package = null;
	/**
	 * WordPress version number
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	public $wp_version = null;
	/**
	 * WordPress site URL
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	public $wp_site_url = null;
	/**
	 * Request headers container
	 *
	 * @var Headers
	 * @since 1.0.0
	 */
	public $headers;

	/**
	 * Other, arbitrary request properties
	 *
	 * @var array
	 * @since 1.0.0
	 */
	protected $props = array();

	/**
	 * Constructor
	 *
	 * Initialize a new request object with query parameters, headers and connection info.
	 *
	 * @param array $query Request query parameters.
	 * @param array $headers Request HTTP headers.
	 * @param string $client_ip Client's IP address, defaults to '0.0.0.0'.
	 * @param string $http_method HTTP method used for the request, defaults to 'GET'.
	 * @since 1.0.0
	 */
	public function __construct( $query, $headers, $client_ip = '0.0.0.0', $http_method = 'GET' ) {
		$this->query       = $query;
		$this->headers     = new Headers( $headers );
		$this->client_ip   = $client_ip;
		$this->http_method = strtoupper( $http_method );
		$this->action      = preg_replace( '@[^a-z0-9\-_]@i', '', $this->param( 'action', '' ) );
		$this->slug        = preg_replace( '@[:?/\\\]@i', '', $this->param( 'slug', '' ) );

		//If the request was made via the WordPress HTTP API  we can usually
		//get WordPress version and site URL from the user agent.
		$user_agent    = $this->headers->get( 'User-Agent', '' );
		$default_regex = '@WordPress/(?P<version>\d[^;]*?);\s+(?P<url>https?://.+?)(?:\s|;|$)@i';
		$wp_com_regex  = '@WordPress\.com;\s+(?P<url>https?://.+?)(?:\s|;|$)@i';

		if ( preg_match( $default_regex, $user_agent, $matches ) ) {
			//A regular WordPress site using the default user agent.
			$this->wp_version  = $matches['version'];
			$this->wp_site_url = $matches['url'];
		} elseif ( preg_match( $wp_com_regex, $user_agent, $matches ) ) {
			//A site hosted on WordPress.com. In this case, the user agent does not include a version number.
			$this->wp_site_url = $matches['url'];
		}
	}

	/**
	 * Get the value of a query parameter.
	 *
	 * Safely retrieves a parameter from the query array with an optional default value.
	 *
	 * @param string $name Parameter name to retrieve.
	 * @param mixed $_default The value to return if the parameter doesn't exist. Defaults to null.
	 * @return mixed The parameter value or default if not found.
	 * @since 1.0.0
	 */
	public function param( $name, $_default = null ) {

		if ( array_key_exists( $name, $this->query ) ) {
			return $this->query[ $name ];
		} else {
			return $_default;
		}
	}

	/**
	 * Magic getter for dynamic properties
	 *
	 * Retrieves dynamically stored properties from the props array.
	 *
	 * @param string $name Property name to retrieve.
	 * @return mixed The property value or null if not found.
	 * @since 1.0.0
	 */
	public function __get( $name ) {

		if ( array_key_exists( $name, $this->props ) ) {
			return $this->props[ $name ];
		}

		return null;
	}

	/**
	 * Magic setter for dynamic properties
	 *
	 * Sets values in the dynamic props array.
	 *
	 * @param string $name Property name to set.
	 * @param mixed $value Value to assign to the property.
	 * @since 1.0.0
	 */
	public function __set( $name, $value ) {
		$this->props[ $name ] = $value;
	}

	/**
	 * Magic isset checker for dynamic properties
	 *
	 * Checks if a dynamic property exists in the props array.
	 *
	 * @param string $name Property name to check.
	 * @return bool Whether the property exists.
	 * @since 1.0.0
	 */
	public function __isset( $name ) {
		return isset( $this->props[ $name ] );
	}

	/**
	 * Magic unset for dynamic properties
	 *
	 * Removes a property from the props array.
	 *
	 * @param string $name Property name to remove.
	 * @since 1.0.0
	 */
	public function __unset( $name ) {
		unset( $this->props[ $name ] );
	}
}
