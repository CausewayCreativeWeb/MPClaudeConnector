<?php
/**
 * The dedicated MCP server (docs/03; non-negotiable 1).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Abilities\AbilityRegistrar;
use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * @covers \Causeway\ClaudeBridge\Mcp\ServerRegistrar
 * @covers \Causeway\ClaudeBridge\Abilities\AbilityRegistrar
 */
final class McpServerTest extends McpTestCase {

	public function test_tools_list_contains_exactly_the_ccb_abilities(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->initialize();

		[ $status, $body ] = $this->rpc( 'tools/list' );
		$body              = json_decode( (string) wp_json_encode( $body ), true );

		$this->assertSame( 200, $status );
		$names = array_column( $body['result']['tools'], 'name' );
		sort( $names );
		$expected = array_map( static fn ( string $n ): string => str_replace( '/', '-', $n ), AbilityRegistrar::names() );
		sort( $expected );
		$this->assertSame( $expected, $names );
		foreach ( $names as $name ) {
			$this->assertStringStartsWith( 'ccb-', $name );
		}
	}

	public function test_tools_carry_behaviour_annotations(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->initialize();

		[ , $body ] = $this->rpc( 'tools/list' );
		$body       = json_decode( (string) wp_json_encode( $body ), true );
		$tools      = array_column( $body['result']['tools'], 'annotations', 'name' );

		foreach ( array( 'ccb-check-connection', 'ccb-get-publishing-guide', 'ccb-list-sections', 'ccb-list-terms', 'ccb-list-posts', 'ccb-get-post' ) as $read ) {
			$this->assertTrue( $tools[ $read ]['readOnlyHint'] ?? false, $read );
			$this->assertFalse( $tools[ $read ]['destructiveHint'] ?? true, $read );
		}
		$this->assertFalse( $tools['ccb-create-post']['readOnlyHint'] ?? true );
		$this->assertFalse( $tools['ccb-create-post']['idempotentHint'] ?? true );
		$this->assertFalse( $tools['ccb-update-post']['readOnlyHint'] ?? true );
		$this->assertTrue( $tools['ccb-update-post']['idempotentHint'] ?? false );
	}

	public function test_default_server_and_meta_abilities_are_not_created(): void {
		if ( getenv( 'CCB_TEST_WITH_STANDALONE_ADAPTER' ) ) {
			$this->markTestSkipped( 'With the standalone plugin active its default server exists; see StandaloneAdapterTest.' );
		}
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp/ccb', $routes );
		$this->assertArrayNotHasKey( '/mcp/mcp-adapter-default-server', $routes );
		$this->assertFalse( wp_has_ability( 'mcp-adapter/execute-ability' ) );
		$this->assertFalse( wp_has_ability( 'mcp-adapter/discover-abilities' ) );
	}

	public function test_ccb_abilities_are_hidden_from_rest_and_default_servers(): void {
		foreach ( AbilityRegistrar::names() as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertNotNull( $ability, $name );
			$meta = $ability->get_meta();
			$this->assertFalse( $meta['show_in_rest'], $name );
			$this->assertFalse( $meta['mcp']['public'], $name );
			$this->assertNotTrue( $meta['public'] ?? null, $name );
		}
	}

	public function test_unauthenticated_requests_get_401(): void {
		wp_set_current_user( 0 );
		[ $status ] = $this->rpc( 'tools/list' );
		$this->assertSame( 401, $status );
	}

	public function test_users_without_the_bridge_role_get_403(): void {
		$this->login_as( 'editor' );
		[ $status ] = $this->rpc( 'tools/list' );
		$this->assertSame( 403, $status );
	}

	public function test_other_plugins_abilities_cannot_be_called_through_the_server(): void {
		add_action(
			'wp_abilities_api_init',
			static function (): void {
				wp_register_ability(
					'other/delete-everything',
					array(
						'label'               => 'Other',
						'description'         => 'Not ours',
						'category'            => 'site',
						'execute_callback'    => static fn () => array( 'done' => true ),
						'permission_callback' => '__return_true',
						'meta'                => array( 'public' => true ),
					)
				);
			}
		);
		$this->login_as( 'administrator' );
		$this->initialize();

		[ , $body ] = $this->rpc(
			'tools/call',
			array(
				'name'      => 'other-delete-everything',
				'arguments' => new \stdClass(),
			)
		);
		$body       = json_decode( (string) wp_json_encode( $body ), true );

		$this->assertArrayHasKey( 'error', $body, 'Unknown tool is a protocol error' );
	}
}
