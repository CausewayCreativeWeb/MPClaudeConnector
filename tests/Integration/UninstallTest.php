<?php
/**
 * Uninstall (docs/06 "Uninstall").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Uninstaller;

/**
 * @covers \Causeway\ClaudeBridge\Uninstaller
 */
final class UninstallTest extends IntegrationTestCase {

	public function test_without_the_delete_option_all_data_is_kept(): void {
		delete_option( Uninstaller::OPTION_DELETE_DATA );

		Uninstaller::run();

		$this->assertTrue( $this->table_exists( Schema::ACTIVITY ) );
		$this->assertNotNull( get_role( RoleManager::ROLE_CONTRIBUTOR ) );
		$this->assertNotFalse( get_option( ConfigRepository::OPTION ) );
	}

	public function test_with_the_delete_option_plugin_data_goes_and_content_stays(): void {
		$contributor = self::factory()->user->create( array( 'role' => RoleManager::ROLE_CONTRIBUTOR ) );
		$publisher   = self::factory()->user->create( array( 'role' => RoleManager::ROLE_PUBLISHER ) );
		$post        = self::factory()->post->create( array( 'post_author' => $contributor ) );
		update_post_meta( $post, '_ccb_created_via', '{"via":"claude"}' );
		update_post_meta( $post, 'visits_location', '{"lat":53.39,"lng":-2.597}' );
		set_transient( 'ccb_site_structure', array( 'x' ), 300 );
		update_option( Uninstaller::OPTION_DELETE_DATA, true );

		Uninstaller::run();

		// Plugin data removed.
		foreach ( Schema::tables() as $table ) {
			$this->assertFalse( $this->table_exists( $table ), "{$table} should be dropped" );
		}
		$this->assertFalse( get_option( ConfigRepository::OPTION ) );
		$this->assertFalse( get_option( Schema::OPTION_VERSION ) );
		$this->assertFalse( get_transient( 'ccb_site_structure' ) );
		$this->assertSame( '', get_post_meta( $post, '_ccb_created_via', true ) );
		$this->assertNull( get_role( RoleManager::ROLE_CONTRIBUTOR ) );
		$this->assertNull( get_role( RoleManager::ROLE_PUBLISHER ) );
		$this->assertArrayNotHasKey( RoleManager::CAP_USE, get_role( 'administrator' )->capabilities );

		// Users moved to WordPress roles first (docs/06).
		$this->assertSame( array( 'contributor' ), array_values( get_userdata( $contributor )->roles ) );
		$this->assertSame( array( 'editor' ), array_values( get_userdata( $publisher )->roles ) );

		// Content untouched.
		$this->assertNotNull( get_post( $post ) );
		$this->assertSame( '{"lat":53.39,"lng":-2.597}', get_post_meta( $post, 'visits_location', true ) );
	}
}
