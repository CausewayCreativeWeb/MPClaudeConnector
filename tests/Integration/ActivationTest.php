<?php
/**
 * Activation, roles, capabilities, default config and cron (docs/02, docs/06).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Activation;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Roles\RoleManager;
use WPDieException;

/**
 * @covers \Causeway\ClaudeBridge\Activation
 * @covers \Causeway\ClaudeBridge\Roles\RoleManager
 * @covers \Causeway\ClaudeBridge\Db\Schema
 */
final class ActivationTest extends IntegrationTestCase {

	public function test_activation_creates_all_tables_and_records_the_schema_version(): void {
		foreach ( Schema::tables() as $table ) {
			$this->assertTrue( $this->table_exists( $table ), "Table {$table} should exist" );
		}
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::OPTION_VERSION ) );
		$this->assertSame( CCB_VERSION, get_option( Activation::OPTION_INSTALLED_VERSION ) );
	}

	public function test_tables_accept_rows_with_the_specified_columns(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the sniff mistakes the 'create' column values for DDL.
		$this->assertSame(
			1,
			$wpdb->insert(
				Schema::table( Schema::ACTIVITY ),
				array(
					'created_at_gmt'    => '2026-11-06 10:00:00',
					'user_id'           => 1,
					'app_password_uuid' => '0b6a6f6e-3c8c-4b5c-9a52-2b7a0b0b1c11',
					'app_password_name' => 'Sarah – office laptop',
					'action'            => 'create',
					'ability'           => 'ccb/create-post',
					'post_id'           => 10,
					'section'           => 'news',
					'result'            => 'success',
					'config_version'    => 1,
					'request_ref'       => 'abc123def456',
					'details'           => '{}',
				)
			)
		);
		$this->assertSame(
			1,
			$wpdb->insert(
				Schema::table( Schema::TOKENS ),
				array(
					'token_hash'        => str_repeat( 'a', 64 ),
					'user_id'           => 1,
					'post_id'           => 10,
					'action'            => 'publish',
					'payload_hash'      => str_repeat( 'b', 64 ),
					'post_modified_gmt' => '2026-11-06 10:00:00',
					'expires_at_gmt'    => '2026-11-06 10:10:00',
				)
			)
		);
		$this->assertSame(
			1,
			$wpdb->insert(
				Schema::table( Schema::UPLOAD_LINKS ),
				array(
					'token_hash'     => str_repeat( 'c', 64 ),
					'user_id'        => 1,
					'post_id'        => 10,
					'purpose'        => 'field_file',
					'field_key'      => 'field_655493153192f',
					'expires_at_gmt' => '2026-11-06 10:15:00',
					'created_at_gmt' => '2026-11-06 10:00:00',
				)
			)
		);
		$this->assertSame(
			1,
			$wpdb->insert(
				Schema::table( Schema::IDEMPOTENCY ),
				array(
					'user_id'        => 1,
					'idem_key'       => 'uuid-from-claude',
					'post_id'        => 10,
					'response'       => '{}',
					'created_at_gmt' => '2026-11-06 10:00:00',
				)
			)
		);
		// phpcs:enable
	}

	public function test_activation_is_idempotent(): void {
		$repository = new ConfigRepository();
		$version    = $repository->version();
		$rows       = $this->row_count( Schema::CONFIG_VERSIONS );

		ccb_activate( false );
		ccb_activate( false );

		$this->assertSame( $version, $repository->version() );
		$this->assertSame( $rows, $this->row_count( Schema::CONFIG_VERSIONS ) );
		$expected = RoleManager::contributor_caps();
		$actual   = get_role( RoleManager::ROLE_CONTRIBUTOR )->capabilities;
		ksort( $expected );
		ksort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_first_activation_stores_a_valid_default_config_as_version_1(): void {
		global $wpdb;
		delete_option( ConfigRepository::OPTION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table name from constants.
		$wpdb->query( 'DELETE FROM `' . Schema::table( Schema::CONFIG_VERSIONS ) . '`' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		ccb_activate( false );

		$repository = new ConfigRepository();
		$config     = $repository->get();

		$this->assertSame( 1, $repository->version() );
		$this->assertSame( array(), $config['sections'], 'No sections are enabled by default' );
		$this->assertSame( get_option( 'admin_email' ), $config['support']['email'] );
		$this->assertTrue( $repository->validate_stored()->is_valid() );

		$versions = $repository->versions();
		$this->assertCount( 1, $versions );
		$this->assertSame( 'activation', $versions[0]['source'] );
		$this->assertSame( $admin, $versions[0]['user_id'] );

		$this->assertFalse( wp_load_alloptions()[ ConfigRepository::OPTION ] ?? false, 'Config must not be autoloaded' );
		$this->assertStringContainsString( '"sections":{}', (string) get_option( ConfigRepository::OPTION ) );
	}

	public function test_contributor_role_has_exactly_the_specified_capabilities(): void {
		$caps = get_role( RoleManager::ROLE_CONTRIBUTOR )->capabilities;
		ksort( $caps );
		$expected = RoleManager::contributor_caps();
		ksort( $expected );

		$this->assertSame( $expected, $caps );
		$this->assertArrayNotHasKey( 'publish_posts', $caps );
		$this->assertArrayNotHasKey( RoleManager::CAP_PUBLISH, $caps );
		$this->assertSame( 'AI Contributor', wp_roles()->roles[ RoleManager::ROLE_CONTRIBUTOR ]['name'] );
	}

	public function test_publisher_role_adds_publishing_and_activity(): void {
		$caps = get_role( RoleManager::ROLE_PUBLISHER )->capabilities;

		foreach ( array_keys( RoleManager::contributor_caps() ) as $cap ) {
			$this->assertArrayHasKey( $cap, $caps );
		}
		$this->assertTrue( $caps['publish_posts'] );
		$this->assertTrue( $caps[ RoleManager::CAP_PUBLISH ] );
		$this->assertTrue( $caps[ RoleManager::CAP_VIEW_ACTIVITY ] );
		$this->assertSame( 'AI Publisher', wp_roles()->roles[ RoleManager::ROLE_PUBLISHER ]['name'] );
	}

	public function test_bridge_roles_never_hold_forbidden_capabilities(): void {
		foreach ( array( RoleManager::ROLE_CONTRIBUTOR, RoleManager::ROLE_PUBLISHER ) as $role ) {
			foreach ( RoleManager::FORBIDDEN_CAPS as $cap ) {
				$this->assertArrayNotHasKey( $cap, get_role( $role )->capabilities, "{$role} must not have {$cap}" );
			}
		}
	}

	/**
	 * Who can do what (docs/02 "What each role can do").
	 *
	 * @dataProvider capability_matrix
	 *
	 * @param string $role     Role to test.
	 * @param string $cap      Capability.
	 * @param bool   $expected Whether the user should have it.
	 */
	public function test_capability_matrix( string $role, string $cap, bool $expected ): void {
		$user = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertSame( $expected, user_can( $user, $cap ), "{$role} / {$cap}" );
	}

	/**
	 * [role, capability, expected].
	 *
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function capability_matrix(): array {
		return array(
			'contributor uses the connection'   => array( RoleManager::ROLE_CONTRIBUTOR, RoleManager::CAP_USE, true ),
			'contributor cannot publish via WP' => array( RoleManager::ROLE_CONTRIBUTOR, 'publish_posts', false ),
			'contributor cannot publish via AI' => array( RoleManager::ROLE_CONTRIBUTOR, RoleManager::CAP_PUBLISH, false ),
			'contributor sees no activity'      => array( RoleManager::ROLE_CONTRIBUTOR, RoleManager::CAP_VIEW_ACTIVITY, false ),
			'contributor edits others\' posts'  => array( RoleManager::ROLE_CONTRIBUTOR, 'edit_others_posts', true ),
			'contributor deletes published'     => array( RoleManager::ROLE_CONTRIBUTOR, 'delete_published_posts', true ),
			'contributor cannot create terms'   => array( RoleManager::ROLE_CONTRIBUTOR, 'manage_categories', false ),
			'contributor has no unfiltered html' => array( RoleManager::ROLE_CONTRIBUTOR, 'unfiltered_html', false ),
			'contributor cannot edit pages'     => array( RoleManager::ROLE_CONTRIBUTOR, 'edit_pages', false ),
			'publisher publishes via WP'        => array( RoleManager::ROLE_PUBLISHER, 'publish_posts', true ),
			'publisher publishes via AI'        => array( RoleManager::ROLE_PUBLISHER, RoleManager::CAP_PUBLISH, true ),
			'publisher sees activity'           => array( RoleManager::ROLE_PUBLISHER, RoleManager::CAP_VIEW_ACTIVITY, true ),
			'publisher cannot change settings'  => array( RoleManager::ROLE_PUBLISHER, 'manage_options', false ),
			'publisher has no unfiltered html'  => array( RoleManager::ROLE_PUBLISHER, 'unfiltered_html', false ),
			'admin uses the connection'         => array( 'administrator', RoleManager::CAP_USE, true ),
			'admin publishes via AI'            => array( 'administrator', RoleManager::CAP_PUBLISH, true ),
			'admin sees activity'               => array( 'administrator', RoleManager::CAP_VIEW_ACTIVITY, true ),
			'editor has no connection'          => array( 'editor', RoleManager::CAP_USE, false ),
			'subscriber has no connection'      => array( 'subscriber', RoleManager::CAP_USE, false ),
		);
	}

	public function test_administrators_get_bridge_caps_even_if_removed_from_the_role(): void {
		get_role( 'administrator' )->remove_cap( RoleManager::CAP_PUBLISH );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( user_can( $admin, RoleManager::CAP_PUBLISH ) );
	}

	public function test_forbidden_caps_added_to_a_bridge_user_are_ignored(): void {
		$user_id = self::factory()->user->create( array( 'role' => RoleManager::ROLE_CONTRIBUTOR ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'unfiltered_html' );
		$user->add_cap( 'manage_categories' );

		$this->assertFalse( user_can( $user_id, 'unfiltered_html' ) );
		$this->assertFalse( user_can( $user_id, 'manage_categories' ) );
	}

	public function test_daily_jobs_are_scheduled_at_their_site_time_slots(): void {
		foreach ( array( Activation::CRON_HEALTH => '03:15', Activation::CRON_PRUNE => '03:45' ) as $hook => $time ) {
			$next = wp_next_scheduled( $hook );
			$this->assertNotFalse( $next, "{$hook} should be scheduled" );
			$this->assertSame( $time, wp_date( 'H:i', $next ) );
			$this->assertSame( 'daily', wp_get_schedule( $hook ) );
		}
	}

	public function test_deactivation_unschedules_jobs_but_keeps_data_and_roles(): void {
		Activation::deactivate();

		$this->assertFalse( wp_next_scheduled( Activation::CRON_HEALTH ) );
		$this->assertFalse( wp_next_scheduled( Activation::CRON_PRUNE ) );
		$this->assertTrue( $this->table_exists( Schema::ACTIVITY ) );
		$this->assertNotNull( get_role( RoleManager::ROLE_CONTRIBUTOR ) );
		$this->assertNotFalse( get_option( ConfigRepository::OPTION ) );
	}

	public function test_upgrade_reapplies_roles_after_a_plugin_update(): void {
		get_role( RoleManager::ROLE_CONTRIBUTOR )->remove_cap( 'edit_others_posts' );
		get_role( RoleManager::ROLE_CONTRIBUTOR )->add_cap( 'manage_categories' );
		update_option( Activation::OPTION_INSTALLED_VERSION, '0.0.1' );

		Activation::maybe_upgrade();

		$caps = get_role( RoleManager::ROLE_CONTRIBUTOR )->capabilities;
		$this->assertArrayHasKey( 'edit_others_posts', $caps );
		$this->assertArrayNotHasKey( 'manage_categories', $caps );
		$this->assertSame( CCB_VERSION, get_option( Activation::OPTION_INSTALLED_VERSION ) );
	}

	public function test_network_activation_is_refused(): void {
		$this->expectException( WPDieException::class );
		ccb_activate( true );
	}

	public function test_next_occurrence_is_always_in_the_future(): void {
		$next = Activation::next_occurrence( '03:15' );
		$this->assertGreaterThan( time(), $next );
		$this->assertLessThanOrEqual( time() + DAY_IN_SECONDS, $next );
	}
}
