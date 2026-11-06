<?php
/**
 * Error responses for abilities (docs/03 "Errors" and "Error codes").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use WP_Error;

/**
 * Builds the WP_Error every ability returns.
 *
 * The MCP Adapter passes only an error's message text to Claude, not its code or data, so the
 * message carries everything Claude needs to react: the plain-English reason, the fix, the
 * `ccb_*` code the skill keys on, and the reference ID that matches the activity log.
 * The same values are kept in the error data for logging and tests.
 */
final class AbilityError {

	/**
	 * Creates an error.
	 *
	 * @param string               $code        One of the codes in docs/03.
	 * @param string               $message     Plain-English reason the user can read.
	 * @param int                  $status      HTTP-style status.
	 * @param string|null          $field       Input field at fault, if any.
	 * @param string|null          $fix         What to do about it.
	 * @param string|null          $request_ref Activity log reference.
	 * @param array<string, mixed> $extra       Extra data (e.g. retry_after).
	 */
	public static function make( string $code, string $message, int $status, ?string $field = null, ?string $fix = null, ?string $request_ref = null, array $extra = array() ): WP_Error {
		$data = array_merge(
			$extra,
			array(
				'status'        => $status,
				'field'         => $field,
				'fix'           => $fix,
				'request_ref'   => $request_ref,
				'plain_message' => $message,
			)
		);
		return new WP_Error( $code, self::text( $code, $message, $field, $fix, $request_ref, $extra ), $data );
	}

	/**
	 * Adds the reference ID to an error made before the reference was known.
	 *
	 * @param WP_Error $error       Error.
	 * @param string   $request_ref Reference.
	 */
	public static function with_ref( WP_Error $error, string $request_ref ): WP_Error {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();
		if ( ! empty( $data['request_ref'] ) ) {
			return $error;
		}
		$plain = isset( $data['plain_message'] ) ? (string) $data['plain_message'] : $error->get_error_message();
		return self::make(
			(string) $error->get_error_code(),
			$plain,
			(int) ( $data['status'] ?? 400 ),
			isset( $data['field'] ) ? (string) $data['field'] : null,
			isset( $data['fix'] ) ? (string) $data['fix'] : null,
			$request_ref,
			array_diff_key( $data, array_flip( array( 'status', 'field', 'fix', 'request_ref', 'plain_message' ) ) )
		);
	}

	/**
	 * Message text sent to Claude.
	 *
	 * @param string               $code        Code.
	 * @param string               $message     Plain message.
	 * @param string|null          $field       Field.
	 * @param string|null          $fix         Fix.
	 * @param string|null          $request_ref Reference.
	 * @param array<string, mixed> $extra       Extra data.
	 */
	private static function text( string $code, string $message, ?string $field, ?string $fix, ?string $request_ref, array $extra ): string {
		$text = $message;
		if ( null !== $fix && '' !== $fix ) {
			/* translators: %s: how to fix the problem */
			$text .= ' ' . sprintf( __( 'Fix: %s', 'causeway-claude-bridge' ), $fix );
		}
		$details = array( 'code: ' . $code );
		if ( null !== $field ) {
			$details[] = 'field: ' . $field;
		}
		if ( isset( $extra['retry_after'] ) ) {
			$details[] = 'retry_after: ' . (int) $extra['retry_after'];
		}
		if ( null !== $request_ref ) {
			$details[] = 'ref: ' . $request_ref;
		}
		return $text . ' [' . implode( '; ', $details ) . ']';
	}

	/**
	 * The message without the bracketed details, for the activity log.
	 *
	 * @param WP_Error $error Error.
	 */
	public static function plain( WP_Error $error ): string {
		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['plain_message'] ) ? (string) $data['plain_message'] : $error->get_error_message();
	}
}
