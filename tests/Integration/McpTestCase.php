<?php
/**
 * Base class for tests that talk to the `ccb` MCP server through the REST API.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Integration;

use Causeway\ClaudeBridge\Abilities\AbstractAbility;
use Causeway\ClaudeBridge\Audit\RequestContext;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Config\Presets;
use Causeway\ClaudeBridge\Config\SiteStructure;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Mcp\ServerRegistrar;
use WP_REST_Request;

/**
 * Sends JSON-RPC to /wp-json/mcp/ccb in-process, over "HTTPS", as a given user.
 */
abstract class McpTestCase extends IntegrationTestCase {

	/**
	 * MCP session for the current user.
	 *
	 * @var string|null
	 */
	private ?string $session = null;

	private int $next_id = 1;

	public function set_up(): void {
		parent::set_up();
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

		// The adapter registers its routes once per process, on the first rest_api_init, so keep one REST server.
		rest_get_server();

		AbstractAbility::reset_request_state();
		RequestContext::reset();
		$this->session = null;
		add_filter( 'pre_http_request', array( $this, 'http_double' ), 99, 3 );
	}

	/**
	 * Stands in for HTTP: a post's public URL answers 200 when it's published and 404 otherwise;
	 * anything else not answered by an earlier filter fails fast, so no test reaches the network.
	 *
	 * @param mixed  $pre  Earlier answer.
	 * @param mixed  $args Request args.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function http_double( mixed $pre, mixed $args, string $url ): mixed {
		unset( $args );
		if ( false !== $pre ) {
			return $pre;
		}
		if ( str_contains( $url, 'ccb_check=' ) ) {
			$post_id = url_to_postid( remove_query_arg( 'ccb_check', $url ) );
			$live    = $post_id > 0 && 'publish' === get_post_status( $post_id );
			return array(
				'response' => array( 'code' => $live ? 200 : 404 ),
				'body'     => '',
				'headers'  => array(),
				'cookies'  => array(),
			);
		}
		return new \WP_Error( 'http_request_failed', 'Blocked in tests: ' . $url );
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTPS'] );
		parent::tear_down();
	}

	/**
	 * Applies the MCP site preset with real terms, so sections exist.
	 */
	protected function configure_mp_site(): void {
		if ( ! term_exists( 'news', 'category' ) ) {
			self::factory()->category->create( array( 'name' => 'News', 'slug' => 'news' ) );
		}
		SiteStructure::clear_cache();
		$repository = new ConfigRepository();
		$preview    = ( new Presets() )->preview( 'mp-site', $repository->get(), ( new SiteStructure() )->get() );
		$saved      = $repository->save( $preview['config'], 1, 'preset' );
		$this->assertIsArray( $saved, is_wp_error( $saved ) ? (string) wp_json_encode( $saved->get_error_data() ) : '' );
	}

	/**
	 * Logs in a new user with the given role.
	 *
	 * @param string $role Role.
	 */
	protected function login_as( string $role ): int {
		$user = self::factory()->user->create( array( 'role' => $role, 'display_name' => 'Test ' . $role ) );
		wp_set_current_user( $user );
		$this->session = null;
		AbstractAbility::reset_request_state();
		RequestContext::reset();
		return $user;
	}

	/**
	 * Sends one JSON-RPC request; returns [HTTP status, decoded body, headers].
	 *
	 * @param string               $method JSON-RPC method.
	 * @param array<string, mixed> $params Params.
	 * @param bool                 $notification Whether to omit the id.
	 * @return array{0: int, 1: mixed, 2: array<string, string>}
	 */
	protected function rpc( string $method, array $params = array(), bool $notification = false ): array {
		$request = new WP_REST_Request( 'POST', ServerRegistrar::rest_path() );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Accept', 'application/json, text/event-stream' );
		if ( null !== $this->session ) {
			$request->set_header( 'Mcp-Session-Id', $this->session );
			$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		}
		$body = array(
			'jsonrpc' => '2.0',
			'method'  => $method,
		);
		if ( ! $notification ) {
			$body['id'] = $this->next_id++;
		}
		if ( array() !== $params ) {
			$body['params'] = $params;
		}
		$request->set_body( (string) wp_json_encode( $body ) );

		$response = rest_do_request( $request );
		$headers  = $response->get_headers();
		return array( $response->get_status(), $response->get_data(), $headers );
	}

	/**
	 * Runs the initialize handshake and remembers the session.
	 */
	protected function initialize(): void {
		[ $status, , $headers ] = $this->rpc(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'ccb-tests',
					'version' => '1.0.0',
				),
			)
		);
		$this->assertSame( 200, $status, 'initialize should succeed' );
		$session = $headers['Mcp-Session-Id'] ?? $headers['mcp-session-id'] ?? null;
		$this->assertIsString( $session, 'initialize returns a session ID' );
		$this->session = $session;
		$this->rpc( 'notifications/initialized', array(), true );
	}

	/**
	 * Calls a tool; returns the result object (content, structuredContent, isError).
	 *
	 * @param string               $tool      MCP tool name, e.g. ccb-get-post.
	 * @param array<string, mixed> $arguments Arguments.
	 * @return array<string, mixed>
	 */
	protected function call_tool( string $tool, array $arguments = array() ): array {
		if ( null === $this->session ) {
			$this->initialize();
		}
		RequestContext::reset();
		AbstractAbility::reset_request_state();
		[ $status, $body ] = $this->rpc(
			'tools/call',
			array(
				'name'      => $tool,
				'arguments' => array() === $arguments ? new \stdClass() : $arguments,
			)
		);
		$this->assertSame( 200, $status, (string) wp_json_encode( $body ) );
		$body = json_decode( (string) wp_json_encode( $body ), true );
		$this->assertArrayHasKey( 'result', $body, (string) wp_json_encode( $body ) );
		return $body['result'];
	}

	/**
	 * Text of a tool result's first content block.
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	protected static function text( array $result ): string {
		return (string) ( $result['content'][0]['text'] ?? '' );
	}

	/**
	 * Structured result of a successful call (falls back to decoding the text block).
	 *
	 * @param array<string, mixed> $result Tool result.
	 * @return array<string, mixed>
	 */
	protected function data( array $result ): array {
		$this->assertFalse( $result['isError'] ?? false, self::text( $result ) );
		if ( isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ) {
			return $result['structuredContent'];
		}
		$decoded = json_decode( self::text( $result ), true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}

	/**
	 * Latest activity rows.
	 *
	 * @param int $limit Rows.
	 * @return list<array<string, mixed>>
	 */
	protected function activity( int $limit = 5 ): array {
		global $wpdb;
		$table = Schema::table( Schema::ACTIVITY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
	}
}
