<?php

namespace Anyape\UpdatePulse\Server\Server\Update;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use RuntimeException;

/**
 * Exception thrown when the server fails to parse a plugin/theme.
 */
class Invalid_Package_Exception extends RuntimeException { }
