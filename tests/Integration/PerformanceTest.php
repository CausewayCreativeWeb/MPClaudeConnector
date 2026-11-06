<?php
/**
 * Query and time budgets per ability call (docs/13 M10 performance pass).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * @coversNothing
 */
final class PerformanceTest extends McpTestCase {

	/**
	 * Calls a tool once to warm caches, then again measuring database queries and time.
	 *
	 * @param string               $tool Tool.
	 * @param array<string, mixed> $args Arguments.
	 * @return array{0: int, 1: float}
	 */
	private function measure( string $tool, array $args = array() ): array {
		global $wpdb;
		$this->data( $this->call_tool( $tool, $args ) );
		$queries = $wpdb->num_queries;
		$start   = microtime( true );
		$this->data( $this->call_tool( $tool, $args ) );
		return array( $wpdb->num_queries - $queries, microtime( true ) - $start );
	}

	public function test_read_abilities_stay_within_their_query_budgets(): void {
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_PUBLISHER );
		self::factory()->post->create_many( 30, array( 'post_status' => 'draft' ) );

		$budgets = array(
			array( 'ccb-check-connection', array(), 12 ),
			array( 'ccb-get-publishing-guide', array(), 12 ),
			array( 'ccb-list-sections', array(), 12 ),
			array( 'ccb-list-terms', array( 'section' => 'news', 'taxonomy' => 'category' ), 25 ),
			array( 'ccb-list-posts', array( 'section' => 'news' ), 20 ),
		);
		foreach ( $budgets as $budget ) {
			list( $queries, $seconds ) = $this->measure( $budget[0], $budget[1] );
			$this->assertLessThanOrEqual( $budget[2], $queries, $budget[0] . ' queries' );
			$this->assertLessThan( 2.0, $seconds, $budget[0] . ' time' );
		}
	}

	public function test_writing_a_post_stays_within_its_query_budget(): void {
		global $wpdb;
		$this->configure_mp_site();
		$this->login_as( RoleManager::ROLE_CONTRIBUTOR );
		$this->data( $this->call_tool( 'ccb-check-connection' ) );
		$queries = $wpdb->num_queries;
		$this->data( $this->call_tool( 'ccb-create-post', array( 'section' => 'news', 'title' => 'Budget', 'content' => '<p>' . str_repeat( 'word ', 300 ) . '</p>' ) ) );
		$used = $wpdb->num_queries - $queries;
		$this->assertLessThanOrEqual( 80, $used );
	}
}
