<?php
/**
 * The dedicated `ccb` MCP server (docs/01, docs/03; non-negotiable 1).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Mcp;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use Causeway\ClaudeBridge\Abilities\AbilityRegistrar;
use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Audit\RequestContext;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Vendor\WP\MCP\Core\McpAdapter;
use Causeway\ClaudeBridge\Vendor\WP\MCP\Core\McpServer;
use Causeway\ClaudeBridge\Vendor\WP\MCP\Transport\HttpTransport;
use WP_Error;

/**
 * Sets up the bundled (Strauss-prefixed) MCP Adapter with one server that lists only `ccb/*` abilities.
 *
 * All adapter-specific code lives here, so moving to the canonical MCP Adapter plugin later
 * (upstream deprecated bundling in 0.7.0) only changes this class.
 *
 * Coexistence with another copy of the adapter on the same site (e.g. the standalone plugin):
 * - The bundled copy never creates its default server or its discover/execute meta-abilities.
 *   The filter that controls this is shared by every copy, so it is answered "no" only when our
 *   bundled copy is the one asking; another copy's default server is the site owner's business.
 * - Our abilities set `meta.mcp.public` to false, so no default server, ours or another's, can
 *   list or run them, and `show_in_rest` false keeps them off the core REST endpoints.
 * - `mcp_adapter_init` fires once per copy; the server is created only on our copy's adapter.
 */
final class ServerRegistrar {

	public const SERVER_ID       = 'ccb';
	public const ROUTE_NAMESPACE = 'mcp';
	public const ROUTE           = 'ccb';

	/**
	 * Hooks the adapter. Call on `plugins_loaded`.
	 */
	public static function register(): void {
		add_filter( 'mcp_adapter_create_default_server', array( self::class, 'filter_default_server' ), 10, 1 );
		add_action( 'mcp_adapter_init', array( self::class, 'create_server' ) );
		add_filter( 'mcp_adapter_tool_call_result', array( self::class, 'filter_tool_result' ), 10, 5 );
		McpAdapter::instance();
	}

	/**
	 * REST path of the server, e.g. `/mcp/ccb` (under `/wp-json`).
	 */
	public static function rest_path(): string {
		return '/' . self::ROUTE_NAMESPACE . '/' . self::ROUTE;
	}

	/**
	 * Full URL of the server.
	 */
	public static function url(): string {
		return rest_url( self::ROUTE_NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Says no to the default server when our bundled copy asks; leaves any other copy's answer alone.
	 *
	 * @param mixed $create Current answer.
	 */
	public static function filter_default_server( mixed $create ): bool {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- identifies which adapter copy applied the filter.
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 8 ) as $frame ) {
			if ( isset( $frame['class'] ) && McpAdapter::class === $frame['class'] ) {
				return false;
			}
		}
		return (bool) $create;
	}

	/**
	 * Creates the `ccb` server on our bundled adapter.
	 *
	 * @param mixed $adapter Adapter instance from whichever copy fired the hook.
	 */
	public static function create_server( mixed $adapter ): void {
		if ( ! $adapter instanceof McpAdapter ) {
			return;
		}
		$result = $adapter->create_server(
			self::SERVER_ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'Claude Publishing',
			'Draft, edit, publish and remove posts on this website.',
			CCB_VERSION,
			array( HttpTransport::class ),
			ErrorHandler::class,
			null,
			AbilityRegistrar::names(),
			array(),
			array(),
			array( self::class, 'transport_permission' )
		);
		if ( $result instanceof WP_Error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server setup failure must reach the PHP log.
			error_log( 'Claude Publishing: could not create the MCP server: ' . $result->get_error_message() );
		}
	}

	/**
	 * Endpoint-level check before any tool runs. Unauthenticated requests get 401 (the health check
	 * relies on that); logged-in users without the Bridge capability get 403. HTTPS and the finer
	 * rules are enforced by each ability, with plain-English messages.
	 *
	 * @param mixed $request REST request (unused).
	 */
	public static function transport_permission( mixed $request = null ): bool {
		unset( $request );
		return is_user_logged_in() && current_user_can( RoleManager::CAP_USE );
	}

	/**
	 * Audits and formats errors WordPress raises before our ability code runs (invalid input,
	 * invalid output), so every call gets an activity row and Claude sees a `ccb_*` code.
	 *
	 * @param mixed  $result    Tool result.
	 * @param mixed  $args      Tool arguments.
	 * @param string $tool_name MCP tool name.
	 * @param mixed  $tool      Tool.
	 * @param mixed  $server    Server.
	 * @return mixed
	 */
	public static function filter_tool_result( mixed $result, mixed $args, string $tool_name, mixed $tool, mixed $server ): mixed {
		unset( $args, $tool );
		if ( ! $result instanceof WP_Error || ! $server instanceof McpServer || self::SERVER_ID !== $server->get_server_id() ) {
			return $result;
		}
		$data = $result->get_error_data();
		if ( is_array( $data ) && ! empty( $data['request_ref'] ) ) {
			return $result; // Already formatted and audited by the ability.
		}

		$code  = (string) $result->get_error_code();
		$error = 'ability_invalid_output' === $code
			? AbilityError::make( 'ccb_internal', __( 'The website produced an unexpected response.', 'causeway-claude-bridge' ), 500, null, __( 'Tell the site\'s support contact, quoting the reference.', 'causeway-claude-bridge' ), RequestContext::request_ref() )
			: AbilityError::make( 'ccb_invalid_input', wp_strip_all_tags( $result->get_error_message() ), 400, null, __( 'Check the input against the tool\'s schema and the publishing guide.', 'causeway-claude-bridge' ), RequestContext::request_ref() );

		$ability = null;
		foreach ( AbilityRegistrar::abilities() as $candidate ) {
			if ( str_replace( '/', '-', $candidate->name() ) === $tool_name ) {
				$ability = $candidate;
			}
		}

		ActivityLog::record(
			array(
				'action'     => null === $ability ? 'get' : $ability->audit_action(),
				'ability'    => null === $ability ? $tool_name : $ability->name(),
				'result'     => 'ability_invalid_output' === $code ? 'error' : 'rejected',
				'error_code' => $error->get_error_code(),
				'message'    => AbilityError::plain( $error ),
			),
			array( 'wp_error_code' => $code )
		);
		return $error;
	}
}
