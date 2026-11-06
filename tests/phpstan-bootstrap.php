<?php
/**
 * Constants and autoloading PHPStan needs to analyse the plugin.
 *
 * @package Causeway\ClaudeBridge
 */

define( 'CCB_VERSION', '0.0.0' );
define( 'CCB_FILE', __DIR__ . '/../causeway-claude-bridge.php' );
define( 'CCB_DIR', dirname( __DIR__ ) . '/' );
define( 'CCB_MIN_WP', '6.9' );
define( 'CCB_MIN_PHP', '8.1' );

require_once dirname( __DIR__ ) . '/vendor-prefixed/autoload.php';
