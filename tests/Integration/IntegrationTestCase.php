<?php
/**
 * Base class for integration tests.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Db\Schema;
use WP_UnitTestCase;

/**
 * Runs each test against an activated plugin with real (not temporary) tables.
 */
abstract class IntegrationTestCase extends WP_UnitTestCase {

	/**
	 * The test suite turns CREATE TABLE into temporary tables; the plugin's tables must be real
	 * so SHOW TABLES, dbDelta and DROP behave as on a site.
	 */
	public function set_up(): void {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		ccb_activate( false );
	}

	/**
	 * Whether a plugin table exists.
	 *
	 * @param string $table Unprefixed table name.
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;
		$name = Schema::table( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	/**
	 * Number of rows in a plugin table.
	 *
	 * @param string $table Unprefixed table name.
	 */
	protected function row_count( string $table ): int {
		global $wpdb;
		$name = Schema::table( $table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" );
	}
}
