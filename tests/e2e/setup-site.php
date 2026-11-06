<?php
/**
 * Sets up the browser-test site (bin/e2e-server.sh): installs WordPress, activates ACF and the plugin,
 * applies the MP preset with real terms and creates the users the tests log in as.
 *
 * Usage: php tests/e2e/setup-site.php <site dir> install|configure
 * (two runs, so ACF and the fixtures' field groups are loaded when the preset is applied)
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI script.

if ( 'cli' !== PHP_SAPI || ! isset( $argv[1], $argv[2] ) ) {
	exit( 1 );
}
$ccb_site = rtrim( $argv[1], '/' );
$ccb_step = $argv[2];

$_SERVER['HTTP_HOST']   = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
if ( 'install' === $ccb_step ) {
	define( 'WP_INSTALLING', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
}

require $ccb_site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
add_filter( 'pre_wp_mail', '__return_true' );

if ( 'configure' === $ccb_step ) {
	wp_set_current_user( 1 );
	Causeway\ClaudeBridge\Config\SiteStructure::clear_cache();
	$ccb_repo    = new Causeway\ClaudeBridge\Config\ConfigRepository();
	$ccb_preview = ( new Causeway\ClaudeBridge\Config\Presets() )->preview( 'mp-site', $ccb_repo->get(), ( new Causeway\ClaudeBridge\Config\SiteStructure() )->get() );
	$ccb_saved   = $ccb_repo->save( $ccb_preview['config'], 1, 'preset', 'MP preset', null, true );
	if ( is_wp_error( $ccb_saved ) ) {
		fwrite( STDERR, wp_json_encode( $ccb_saved->get_error_data() ) . "\n" );
		exit( 1 );
	}
	echo 'Site ready: sections ' . implode( ', ', array_keys( $ccb_saved['sections'] ) ) . "\n";
	exit( 0 );
}

wp_install( 'Example Constituency', 'admin', 'admin@example.org', true, '', 'e2e-password' );
update_option( 'permalink_structure', '/%postname%/' );
wp_set_current_user( 1 );

foreach ( array( 'advanced-custom-fields/acf.php', 'causeway-claude-bridge/causeway-claude-bridge.php' ) as $ccb_plugin ) {
	$ccb_result = activate_plugin( $ccb_plugin );
	if ( is_wp_error( $ccb_result ) ) {
		fwrite( STDERR, $ccb_plugin . ': ' . $ccb_result->get_error_message() . "\n" );
		exit( 1 );
	}
}

foreach ( array( 'News', 'Health', 'Transport', 'Schools' ) as $ccb_name ) {
	wp_insert_term( $ccb_name, 'category' );
}

$ccb_users = array(
	array( 'publisher', 'Mike Publisher', 'ccb_publisher' ),
	array( 'contributor', 'Sam Contributor', 'ccb_contributor' ),
	array( 'casey', 'Casey Contributor', 'ccb_contributor' ),
);
foreach ( $ccb_users as $ccb_user ) {
	$ccb_id = wp_insert_user(
		array(
			'user_login'   => $ccb_user[0],
			'user_pass'    => 'e2e-password',
			'user_email'   => $ccb_user[0] . '@example.org',
			'display_name' => $ccb_user[1],
			'role'         => $ccb_user[2],
		)
	);
	if ( is_wp_error( $ccb_id ) ) {
		fwrite( STDERR, $ccb_id->get_error_message() . "\n" );
		exit( 1 );
	}
	if ( 'contributor' === $ccb_user[0] ) {
		WP_Application_Passwords::create_new_application_password( $ccb_id, array( 'name' => 'Sam – office laptop' ) );
	}
	if ( 'publisher' === $ccb_user[0] ) {
		$ccb_password = WP_Application_Passwords::create_new_application_password( $ccb_id, array( 'name' => 'Mike – test laptop' ) );
		file_put_contents( $ccb_site . '/.e2e-publisher-password', 'publisher:' . $ccb_password[0] );
	}
	if ( 'casey' === $ccb_user[0] ) {
		// Used by setup-pack.spec.js to run the downloaded extension against this site.
		$ccb_password = WP_Application_Passwords::create_new_application_password( $ccb_id, array( 'name' => 'Casey – test laptop' ) );
		file_put_contents( $ccb_site . '/.e2e-app-password', 'casey:' . $ccb_password[0] );
	}
}

echo "WordPress installed\n";
