<?php
/**
 * Bootstrap for integration tests: loads WordPress through its PHPUnit test library.
 *
 * WP_TESTS_DIR points at the test library: wp-env sets it in CI; locally run
 * bin/install-wp-tests.sh, which prints the value to use.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

$ccb_root      = dirname( __DIR__ );
$ccb_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( false === $ccb_tests_dir || '' === $ccb_tests_dir ) {
	$ccb_candidates = glob( $ccb_root . '/.wp-tests/wordpress-tests-lib-*', GLOB_ONLYDIR );
	$ccb_tests_dir  = is_array( $ccb_candidates ) && array() !== $ccb_candidates ? end( $ccb_candidates ) : '';
}

if ( '' === $ccb_tests_dir || ! file_exists( $ccb_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found. Set WP_TESTS_DIR or run bin/install-wp-tests.sh.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $ccb_root . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- name required by the WP test suite.
}
if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) && file_exists( $ccb_tests_dir . '/wp-tests-config.php' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . $ccb_tests_dir . '/wp-tests-config.php' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv -- test bootstrap only.
}

require_once $ccb_root . '/vendor/autoload.php';
require_once $ccb_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $ccb_root ): void {
		require $ccb_root . '/causeway-claude-bridge.php';
	}
);

require $ccb_tests_dir . '/includes/bootstrap.php';
