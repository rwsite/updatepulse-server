<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

/**
 * Simple request class for the update server.
 */
class Request {
	/** @var array Query parameters. */
	public $query = array();
	/** @var string Client's IP address. */
	public $client_ip;
	/** @var string The HTTP method, e.g. "POST" or "GET". */
	public $http_method;
	/** @var string The name of the current action. For example, "get_metadata". */
	public $action;
	/** @var string Plugin or theme slug from the current request. */
	public $slug;
	/** @var Package The package that matches the current slug, if any. */
	public $package = null;

	/** @var string WordPress version number as extracted from the User-Agent header. */
	public $wp_version = null;
	/** @var string WordPress site URL, also from the User-Agent. */
	public $wp_site_url = null;

	/** @var array Other, arbitrary request properties. */
	protected $props = array();

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
	 * @param string $name Parameter name.
	 * @param mixed $_default The value to return if the parameter doesn't exist. Defaults to null.
	 * @return mixed
	 */
	public function param( $name, $_default = null ) {
		if ( array_key_exists( $name, $this->query ) ) {
			return $this->query[ $name ];
		} else {
			return $_default;
		}
	}

	public function __get( $name ) {
		if ( array_key_exists( $name, $this->props ) ) {
			return $this->props[ $name ];
		}
		return null;
	}

	public function __set( $name, $value ) {
		$this->props[ $name ] = $value;
	}

	public function __isset( $name ) {
		return isset( $this->props[ $name ] );
	}

	public function __unset( $name ) {
		unset( $this->props[ $name ] );
	}
}
