<?php
/**
 * Adapter diagnostics handler.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Mcp;

use Causeway\ClaudeBridge\Vendor\WP\MCP\Infrastructure\ErrorHandling\Contracts\McpErrorHandlerInterface;

/**
 * Sends the adapter's diagnostics to the PHP error log, except tool refusals and errors that carry
 * a `ccb_*` code: those are expected outcomes (wrong role, rule broken, rate limit) and are already
 * in the activity log with a reference ID.
 */
final class ErrorHandler implements McpErrorHandlerInterface {

	/**
	 * Logs a diagnostic.
	 *
	 * @param string       $message Message.
	 * @param array<mixed> $context Context.
	 * @param string       $type    Type.
	 */
	public function log( string $message, array $context = array(), string $type = 'error' ): void {
		$code = isset( $context['error_code'] ) && is_string( $context['error_code'] ) ? $context['error_code'] : '';
		if ( str_starts_with( $code, 'ccb_' ) || 'Tool not found' === $message ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server-side diagnostics only.
		error_log( sprintf( '[Claude Publishing MCP %s] %s %s', strtoupper( $type ), $message, (string) wp_json_encode( $context ) ) );
	}
}
