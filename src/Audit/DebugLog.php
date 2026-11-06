<?php
/**
 * Debug mode (docs/08 "Advanced", docs/09 "Secrets").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Audit;

/**
 * When an admin switches debug mode on, every ability call's input and result go to the PHP error log,
 * tagged with the request reference, with secrets and post content removed. Switches itself off after
 * 24 hours.
 */
final class DebugLog {

	public const OPTION = 'ccb_debug_until';

	public const DURATION = DAY_IN_SECONDS;

	/**
	 * Keys whose values are never logged, at any depth (matched case-insensitively).
	 */
	private const SECRET_KEYS = array( 'token', 'password', 'app_password', 'authorization', 'cookie', 'nonce', 'secret', 'content', 'data_base64', 'excerpt', 'url', 'upload_url' );

	private const MAX_STRING = 200;

	/**
	 * Whether debug mode is on. Turns it off once the 24 hours are up.
	 */
	public static function enabled(): bool {
		$until = (int) get_option( self::OPTION, 0 );
		if ( 0 === $until ) {
			return false;
		}
		if ( $until <= time() ) {
			delete_option( self::OPTION );
			return false;
		}
		return true;
	}

	/**
	 * Switches debug mode on for 24 hours, or off.
	 *
	 * @param bool $on On.
	 */
	public static function set( bool $on ): void {
		if ( $on ) {
			update_option( self::OPTION, time() + self::DURATION, false );
		} else {
			delete_option( self::OPTION );
		}
	}

	/**
	 * Logs one event when debug mode is on.
	 *
	 * @param string               $event Event name, e.g. `ccb/create-post input`.
	 * @param array<string, mixed> $data  Data (redacted here).
	 */
	public static function log( string $event, array $data ): void {
		if ( ! self::enabled() ) {
			return;
		}
		$line = sprintf( '[Claude Publishing debug %s] %s %s', RequestContext::request_ref(), $event, (string) wp_json_encode( self::redact( $data ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		/**
		 * Fires for each debug line (tests read it here).
		 *
		 * @param string $line Line.
		 */
		do_action( 'ccb_debug_line', $line );

		/**
		 * Whether to write debug lines to the PHP error log.
		 *
		 * @param bool $write Write.
		 */
		if ( apply_filters( 'ccb_debug_write', true ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug mode is an explicit, time-limited admin choice.
			error_log( $line );
		}
	}

	/**
	 * Removes secrets and content, and shortens long strings.
	 *
	 * @param array<mixed> $data Data.
	 * @return array<mixed>
	 */
	public static function redact( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::is_secret( $key ) ) {
				$data[ $key ] = is_string( $value ) ? sprintf( '[redacted, %d chars]', strlen( $value ) ) : '[redacted]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::redact( $value );
			} elseif ( is_string( $value ) && strlen( $value ) > self::MAX_STRING ) {
				$data[ $key ] = mb_substr( $value, 0, self::MAX_STRING ) . sprintf( '… [%d chars]', strlen( $value ) );
			}
		}
		return $data;
	}

	/**
	 * Whether a key names a secret.
	 *
	 * @param string $key Key.
	 */
	private static function is_secret( string $key ): bool {
		$key = strtolower( $key );
		foreach ( self::SECRET_KEYS as $secret ) {
			if ( $key === $secret || str_ends_with( $key, '_' . $secret ) ) {
				return true;
			}
		}
		return false;
	}
}
