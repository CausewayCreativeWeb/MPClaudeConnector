<?php
/**
 * Plugin Name:       Claude Publishing Bridge
 * Description:       Lets named staff draft, edit, publish and remove website posts from Claude, safely.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Author:            Causeway Creative
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       causeway-claude-bridge
 * Domain Path:       /languages
 *
 * @package Causeway\ClaudeBridge
 */

// This file must stay parseable on old PHP versions so the version notice can be shown.
// Keep modern syntax in src/, which is only loaded after the requirement checks pass.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CCB_VERSION', '0.1.0' );
define( 'CCB_FILE', __FILE__ );
define( 'CCB_DIR', plugin_dir_path( __FILE__ ) );
define( 'CCB_MIN_WP', '6.9' );
define( 'CCB_MIN_PHP', '8.1' );

/**
 * Whether this site meets the plugin's requirements. Doesn't translate anything, so it's safe before `init`.
 *
 * @return bool
 */
function ccb_requirements_met() {
	return version_compare( PHP_VERSION, CCB_MIN_PHP, '>=' )
		&& version_compare( get_bloginfo( 'version' ), CCB_MIN_WP, '>=' )
		&& function_exists( 'wp_register_ability' )
		&& file_exists( CCB_DIR . 'vendor-prefixed/autoload.php' );
}

/**
 * Returns the reasons the plugin can't run here, as translated sentences. Empty when it can.
 * Only call this from `init` or later (admin notices, activation).
 *
 * @return string[]
 */
function ccb_requirement_errors() {
	$errors = array();

	if ( version_compare( PHP_VERSION, CCB_MIN_PHP, '<' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required PHP version, 2: current PHP version */
			__( 'Claude Publishing needs PHP %1$s or later. This site runs PHP %2$s.', 'causeway-claude-bridge' ),
			CCB_MIN_PHP,
			PHP_VERSION
		);
	}

	if ( version_compare( get_bloginfo( 'version' ), CCB_MIN_WP, '<' ) || ! function_exists( 'wp_register_ability' ) ) {
		$errors[] = sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version */
			__( 'Claude Publishing needs WordPress %1$s or later. This site runs WordPress %2$s.', 'causeway-claude-bridge' ),
			CCB_MIN_WP,
			get_bloginfo( 'version' )
		);
	}

	if ( ! file_exists( CCB_DIR . 'vendor-prefixed/autoload.php' ) ) {
		$errors[] = __( 'Claude Publishing is missing its bundled libraries. Install it from a release zip, or run "composer install" if you are working from source.', 'causeway-claude-bridge' );
	}

	return $errors;
}

/**
 * Shows requirement problems to administrators.
 *
 * @return void
 */
function ccb_requirement_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	foreach ( ccb_requirement_errors() as $ccb_error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $ccb_error ) );
	}
}

/**
 * Refuses activation with a clear message when requirements aren't met.
 *
 * @param bool $network_wide Whether this is a network-wide activation.
 * @return void
 */
function ccb_activate( $network_wide = false ) {
	$errors = ccb_requirement_errors();
	if ( $network_wide ) {
		$errors[] = __( 'Claude Publishing can\'t be network activated. Activate it on each site that needs it.', 'causeway-claude-bridge' );
	}
	if ( array() !== $errors ) {
		deactivate_plugins( plugin_basename( CCB_FILE ) );
		wp_die(
			esc_html( implode( ' ', $errors ) ),
			esc_html__( 'Plugin could not be activated', 'causeway-claude-bridge' ),
			array( 'back_link' => true )
		);
	}

	ccb_load();
	\Causeway\ClaudeBridge\Activation::activate();
}

/**
 * Loads the bundled libraries and the plugin's own classes.
 *
 * @return void
 */
function ccb_load() {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	require_once CCB_DIR . 'vendor-prefixed/autoload.php';

	spl_autoload_register(
		static function ( $class_name ) {
			$prefix = 'Causeway\\ClaudeBridge\\';
			if ( 0 !== strpos( $class_name, $prefix ) || 0 === strpos( $class_name, $prefix . 'Vendor\\' ) ) {
				return;
			}
			$file = CCB_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

register_activation_hook( __FILE__, 'ccb_activate' );
register_deactivation_hook(
	__FILE__,
	static function () {
		if ( ccb_requirements_met() ) {
			ccb_load();
			\Causeway\ClaudeBridge\Activation::deactivate();
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! ccb_requirements_met() ) {
			add_action( 'admin_notices', 'ccb_requirement_notice' );
			return;
		}
		ccb_load();
		\Causeway\ClaudeBridge\Plugin::instance()->boot();
	}
);
