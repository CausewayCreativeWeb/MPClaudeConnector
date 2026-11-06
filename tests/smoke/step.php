<?php
/**
 * One step of the release-zip smoke test, run in its own PHP process (like a separate request).
 * Driven by bin/smoke-test.sh. Exits non-zero with a message on the first failed check.
 *
 * Usage: php tests/smoke/step.php <site-dir> <step>
 *
 * @package Causeway\ClaudeBridge
 */

// CLI test script: plain output, direct queries and WordPress's own constants are intended.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput, WordPress.PHP.DevelopmentFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound

[ , $ccb_site, $ccb_step ] = $argv + array( '', '', '' );

$ccb_plugin_file = 'causeway-claude-bridge/causeway-claude-bridge.php';
$ccb_plugin_dir  = $ccb_site . '/wp-content/plugins/causeway-claude-bridge/';

/**
 * Fails the step.
 *
 * @param string $message What went wrong.
 */
function ccb_smoke_fail( string $message ): void {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

/**
 * Asserts a condition.
 *
 * @param bool   $condition Condition.
 * @param string $message   What was expected.
 */
function ccb_smoke_check( bool $condition, string $message ): void {
	if ( ! $condition ) {
		ccb_smoke_fail( $message );
	}
	echo "  ok  {$message}\n";
}

// Any PHP warning or notice raised from the plugin's own files fails the run.
set_error_handler(
	static function ( int $errno, string $errstr, string $errfile, int $errline ) use ( $ccb_plugin_dir ): bool {
		if ( str_starts_with( $errfile, $ccb_plugin_dir ) ) {
			ccb_smoke_fail( "PHP error {$errno} in {$errfile}:{$errline}: {$errstr}" );
		}
		return false;
	}
);

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

if ( 'install' === $ccb_step ) {
	define( 'WP_INSTALLING', true );
}

require $ccb_site . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// Turn wp_die() into an exception so refusals can be checked.
add_filter(
	'wp_die_handler',
	static fn () => static function ( $message ): void {
		throw new RuntimeException( is_wp_error( $message ) ? $message->get_error_message() : wp_strip_all_tags( (string) $message ) );
	}
);

global $wpdb;
$ccb_tables       = array( 'ccb_activity', 'ccb_config_versions', 'ccb_tokens', 'ccb_upload_links', 'ccb_idempotency' );
$ccb_table_exists = static fn ( string $t ): bool => $wpdb->prefix . $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $t ) );

switch ( $ccb_step ) {
	case 'install':
		wp_install( 'Smoke test', 'admin', 'admin@example.org', true, '', 'smoke-test-password' );
		ccb_smoke_check( is_blog_installed(), 'WordPress installed' );
		break;

	case 'activate':
		wp_set_current_user( 1 );
		$ccb_result = activate_plugin( $ccb_plugin_file );
		ccb_smoke_check( null === $ccb_result, 'Plugin activates: ' . ( is_wp_error( $ccb_result ) ? $ccb_result->get_error_message() : 'no error' ) );
		break;

	case 'check-active':
		ccb_smoke_check( is_plugin_active( $ccb_plugin_file ), 'Plugin is active' );
		ccb_smoke_check( class_exists( 'Causeway\\ClaudeBridge\\Plugin', false ), 'Plugin classes loaded on plugins_loaded' );
		ccb_smoke_check( class_exists( 'Causeway\\ClaudeBridge\\Vendor\\Opis\\JsonSchema\\Validator' ), 'Prefixed libraries load from vendor-prefixed' );
		ccb_smoke_check( ! class_exists( 'Opis\\JsonSchema\\Validator' ), 'No unprefixed copy of the libraries is loaded' );
		foreach ( $ccb_tables as $ccb_table ) {
			ccb_smoke_check( $ccb_table_exists( $ccb_table ), "Table {$ccb_table} exists" );
		}
		ccb_smoke_check( null !== get_role( 'ccb_contributor' ) && null !== get_role( 'ccb_publisher' ), 'AI Contributor and AI Publisher roles exist' );
		ccb_smoke_check( user_can( 1, 'ccb_publish' ), 'Administrator can publish through the Bridge' );
		$ccb_repo = new Causeway\ClaudeBridge\Config\ConfigRepository();
		ccb_smoke_check( 1 === $ccb_repo->version(), 'Default config stored as version 1' );
		ccb_smoke_check( $ccb_repo->validate_stored()->is_valid(), 'Stored config is valid' );
		ccb_smoke_check( false !== wp_next_scheduled( 'ccb_daily_health' ) && false !== wp_next_scheduled( 'ccb_daily_prune' ), 'Daily jobs scheduled' );
		ccb_smoke_check( null !== ( new Causeway\ClaudeBridge\Config\Presets() )->read( 'mp-site' ), 'MP site preset ships in the zip' );
		$ccb_structure = ( new Causeway\ClaudeBridge\Config\SiteStructure() )->get();
		ccb_smoke_check( in_array( 'post', array_column( $ccb_structure['post_types'], 'name' ), true ), 'Site discovery runs' );
		break;

	case 'deactivate':
		wp_set_current_user( 1 );
		deactivate_plugins( $ccb_plugin_file );
		ccb_smoke_check( ! is_plugin_active( $ccb_plugin_file ), 'Plugin deactivated' );
		break;

	case 'check-deactivated':
		ccb_smoke_check( false === wp_next_scheduled( 'ccb_daily_health' ), 'Daily jobs unscheduled' );
		ccb_smoke_check( $ccb_table_exists( 'ccb_activity' ), 'Data kept on deactivation' );
		ccb_smoke_check( null !== get_role( 'ccb_contributor' ), 'Roles kept on deactivation' );
		break;

	case 'add-users':
		$ccb_contrib = wp_insert_user( array( 'user_login' => 'contrib', 'user_pass' => wp_generate_password(), 'user_email' => 'c@example.org', 'role' => 'ccb_contributor' ) );
		$ccb_pub     = wp_insert_user( array( 'user_login' => 'pub', 'user_pass' => wp_generate_password(), 'user_email' => 'p@example.org', 'role' => 'ccb_publisher' ) );
		ccb_smoke_check( is_int( $ccb_contrib ) && is_int( $ccb_pub ), 'Bridge users created' );
		$ccb_post = wp_insert_post( array( 'post_title' => 'Kept after uninstall', 'post_status' => 'draft', 'post_author' => $ccb_contrib ) );
		ccb_smoke_check( is_int( $ccb_post ) && $ccb_post > 0, 'Post created' );
		break;

	case 'uninstall-keep':
		delete_option( 'ccb_delete_data_on_uninstall' );
		uninstall_plugin( $ccb_plugin_file );
		ccb_smoke_check( $ccb_table_exists( 'ccb_activity' ), 'Uninstall without "delete data" keeps tables' );
		ccb_smoke_check( false !== get_option( 'ccb_config' ), 'Uninstall without "delete data" keeps the config' );
		break;

	case 'uninstall-delete':
		update_option( 'ccb_delete_data_on_uninstall', true );
		uninstall_plugin( $ccb_plugin_file );
		foreach ( $ccb_tables as $ccb_table ) {
			ccb_smoke_check( ! $ccb_table_exists( $ccb_table ), "Table {$ccb_table} dropped" );
		}
		ccb_smoke_check( false === get_option( 'ccb_config' ) && false === get_option( 'ccb_db_version' ), 'Options removed' );
		ccb_smoke_check( null === get_role( 'ccb_contributor' ) && null === get_role( 'ccb_publisher' ), 'Roles removed' );
		ccb_smoke_check( array( 'contributor' ) === array_values( get_user_by( 'login', 'contrib' )->roles ), 'AI Contributor moved to Contributor' );
		ccb_smoke_check( array( 'editor' ) === array_values( get_user_by( 'login', 'pub' )->roles ), 'AI Publisher moved to Editor' );
		ccb_smoke_check( null !== get_page_by_path( 'kept-after-uninstall', OBJECT, 'post' ) || 1 === count( get_posts( array( 'title' => 'Kept after uninstall', 'post_status' => 'any' ) ) ), 'Posts untouched' );
		break;

	case 'activate-refused':
		wp_set_current_user( 1 );
		// WordPress core refuses first, from the "Requires at least" header; the plugin's own check
		// (which also needs the Abilities API) is the second line of defence.
		try {
			$ccb_result  = activate_plugin( $ccb_plugin_file );
			$ccb_message = is_wp_error( $ccb_result ) ? wp_strip_all_tags( $ccb_result->get_error_message() ) : '';
		} catch ( RuntimeException $e ) {
			$ccb_message = $e->getMessage();
		}
		ccb_smoke_check( '' !== $ccb_message && str_contains( $ccb_message, '6.9' ), 'Activation refused, naming WordPress 6.9: ' . trim( $ccb_message ) );
		ccb_smoke_check( ! is_plugin_active( $ccb_plugin_file ), 'Plugin left inactive' );
		break;

	default:
		ccb_smoke_fail( "Unknown step '{$ccb_step}'" );
}
