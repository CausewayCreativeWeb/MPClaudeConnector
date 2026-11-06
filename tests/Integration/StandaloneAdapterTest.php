<?php
/**
 * Coexistence with the standalone MCP Adapter plugin (docs/13 section 1a).
 * Runs only with CCB_TEST_WITH_STANDALONE_ADAPTER=1.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Abilities\AbilityRegistrar;
use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * @covers \Causeway\ClaudeBridge\Mcp\ServerRegistrar
 */
final class StandaloneAdapterTest extends McpTestCase {

	public function set_up(): void {
		if ( ! getenv( 'CCB_TEST_WITH_STANDALONE_ADAPTER' ) ) {
			$this->markTestSkipped( 'Run with CCB_TEST_WITH_STANDALONE_ADAPTER=1.' );
		}
		parent::set_up();
	}

	public function test_both_servers_exist_side_by_side(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/mcp/ccb', $routes );
		$this->assertArrayHasKey( '/mcp/mcp-adapter-default-server', $routes, 'The standalone plugin keeps its default server' );
		$this->assertTrue( wp_has_ability( 'mcp-adapter/execute-ability' ) );
	}

	public function test_our_server_still_lists_only_ccb_tools(): void {
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->initialize();

		[ , $body ] = $this->rpc( 'tools/list' );
		$body       = json_decode( (string) wp_json_encode( $body ), true );
		$names      = array_column( $body['result']['tools'], 'name' );
		sort( $names );
		$expected = array_map( static fn ( string $n ): string => str_replace( '/', '-', $n ), AbilityRegistrar::names() );
		sort( $expected );

		$this->assertSame( $expected, $names );
	}

	public function test_the_standalone_default_server_cannot_see_or_run_ccb_abilities(): void {
		$this->login_as( 'administrator' );
		$discover = wp_get_ability( 'mcp-adapter/discover-abilities' );
		$execute  = wp_get_ability( 'mcp-adapter/execute-ability' );
		$this->assertNotNull( $discover );
		$this->assertNotNull( $execute );

		$listed = $discover->execute();
		$this->assertIsArray( $listed );
		$names = array_column( $listed['abilities'] ?? array(), 'name' );
		foreach ( $names as $name ) {
			$this->assertStringStartsNotWith( 'ccb/', (string) $name );
		}

		// The default server reports a non-public ability as "not found" through _doing_it_wrong().
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$run = $execute->execute(
			array(
				'ability_name' => 'ccb/list-sections',
				'parameters'   => array(),
			)
		);
		$this->assertTrue( is_wp_error( $run ) || empty( $run['success'] ), 'ccb abilities are not runnable through the default server' );
	}
}
