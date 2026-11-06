<?php
/**
 * Who is calling, from which device, in this request.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Audit;

use WP_Application_Passwords;

/**
 * Request-scoped facts recorded with every activity row: a short reference ID shown to the user on
 * errors, the Application Password used (name and UUID), and the client IP.
 */
final class RequestContext {

	/**
	 * Reference for this request.
	 *
	 * @var string|null
	 */
	private static ?string $request_ref = null;

	/**
	 * 12-character reference shared by every activity row in this request.
	 */
	public static function request_ref(): string {
		return self::$request_ref ??= self::new_ref();
	}

	/**
	 * A fresh 12-character reference.
	 */
	public static function new_ref(): string {
		return bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Starts a new request scope (tests, and long-running processes).
	 */
	public static function reset(): void {
		self::$request_ref = null;
	}

	/**
	 * The Application Password this request authenticated with, if any.
	 *
	 * @return array{uuid: string, name: string}|null
	 */
	public static function app_password(): ?array {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return null;
		}
		$uuid = rest_get_authenticated_app_password();
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return null;
		}
		$password = WP_Application_Passwords::get_user_application_password( get_current_user_id(), $uuid );
		return array(
			'uuid' => $uuid,
			'name' => is_array( $password ) ? (string) $password['name'] : '',
		);
	}

	/**
	 * Client IP in binary form for the VARBINARY(16) column, or null.
	 * Uses REMOTE_ADDR only; forwarded headers can be spoofed.
	 */
	public static function ip_binary(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip || false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$packed = inet_pton( $ip );
		return false === $packed ? null : $packed;
	}
}
