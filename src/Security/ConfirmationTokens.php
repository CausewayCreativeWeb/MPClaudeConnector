<?php
/**
 * Confirmation tokens for live changes (docs/02 "Confirmation tokens"; non-negotiable 3).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Security;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use Causeway\ClaudeBridge\Db\Schema;
use WP_Error;

/**
 * A `prepare-*` ability issues a token after showing the user a summary; the matching action needs it.
 *
 * - 32 random bytes, base64url; only the SHA-256 is stored, compared with hash_equals().
 * - Bound to user, post, action, a hash of the pending change and the post's modified time.
 * - Valid for 10 minutes and used up on the first attempt, even if the action then fails.
 */
final class ConfirmationTokens {

	public const LIFETIME = 10 * MINUTE_IN_SECONDS;

	/**
	 * Issues a token.
	 *
	 * @param int                  $user_id       User.
	 * @param int                  $post_id       Post.
	 * @param string               $action        Action, e.g. `publish`.
	 * @param array<string, mixed> $payload       The pending change (canonicalised and hashed).
	 * @param string               $post_modified Post's `post_modified_gmt` now.
	 * @return array{token: string, expires_at_gmt: string}
	 */
	public static function issue( int $user_id, int $post_id, string $action, array $payload, string $post_modified ): array {
		global $wpdb;
		$token   = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url token.
		$expires = time() + self::LIFETIME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$wpdb->insert(
			Schema::table( Schema::TOKENS ),
			array(
				'token_hash'        => hash( 'sha256', $token ),
				'user_id'           => $user_id,
				'post_id'           => $post_id,
				'action'            => $action,
				'payload_hash'      => self::payload_hash( $payload ),
				'post_modified_gmt' => $post_modified,
				'expires_at_gmt'    => gmdate( 'Y-m-d H:i:s', $expires ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		return array(
			'token'          => $token,
			'expires_at_gmt' => gmdate( 'Y-m-d\TH:i:s\Z', $expires ),
		);
	}

	/**
	 * Verifies and uses up a token. Returns null when the action may go ahead.
	 *
	 * @param string               $token         Token sent with the action.
	 * @param int                  $user_id       Current user.
	 * @param int                  $post_id       Post the action is for.
	 * @param string               $action        Action.
	 * @param array<string, mixed> $payload       The change sent with the action.
	 * @param string               $post_modified Post's `post_modified_gmt` now.
	 */
	public static function consume( string $token, int $user_id, int $post_id, string $action, array $payload, string $post_modified ): ?WP_Error {
		global $wpdb;
		$table = Schema::table( Schema::TOKENS );
		$hash  = hash( 'sha256', $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE token_hash = %s", $hash ), ARRAY_A );
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['token_hash'], $hash ) || null !== $row['used_at_gmt'] || strtotime( $row['expires_at_gmt'] . ' UTC' ) < time() ) {
			return self::invalid();
		}

		// Use it up first, atomically: two simultaneous attempts can't both succeed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET used_at_gmt = %s WHERE token_hash = %s AND used_at_gmt IS NULL", gmdate( 'Y-m-d H:i:s' ), $hash ) );
		if ( 1 !== $claimed ) {
			return self::invalid();
		}

		if ( (int) $row['user_id'] !== $user_id || (int) $row['post_id'] !== $post_id || $row['action'] !== $action ) {
			return self::invalid();
		}
		if ( $row['post_modified_gmt'] !== $post_modified ) {
			return AbilityError::make(
				'ccb_post_changed',
				__( 'The post changed after the summary was shown.', 'causeway-claude-bridge' ),
				409,
				'post_id',
				__( 'Call the prepare tool again and show the user the new summary.', 'causeway-claude-bridge' )
			);
		}
		if ( ! hash_equals( (string) $row['payload_hash'], self::payload_hash( $payload ) ) ) {
			return AbilityError::make(
				'ccb_token_mismatch',
				__( 'What was sent isn\'t what the user confirmed.', 'causeway-claude-bridge' ),
				403,
				'token',
				__( 'Call the prepare tool again with the exact change and show the user the new summary.', 'causeway-claude-bridge' )
			);
		}
		return null;
	}

	/**
	 * Deletes tokens that expired more than a day ago (daily prune, M7).
	 */
	public static function prune(): int {
		global $wpdb;
		$table = Schema::table( Schema::TOKENS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE expires_at_gmt < %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
	}

	/**
	 * Stable hash of a change: keys sorted at every level, so the same change always hashes the same.
	 *
	 * @param array<string, mixed> $payload Change.
	 */
	public static function payload_hash( array $payload ): string {
		return hash( 'sha256', (string) wp_json_encode( self::canonical( $payload ) ) );
	}

	/**
	 * Sorts map keys recursively; lists keep their order.
	 *
	 * @param mixed $value Value.
	 */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		return $value;
	}

	/**
	 * Missing, unknown, expired, used, or someone else's token.
	 */
	private static function invalid(): WP_Error {
		return AbilityError::make(
			'ccb_token_invalid',
			__( 'The confirmation has expired, was already used, or doesn\'t match this action.', 'causeway-claude-bridge' ),
			403,
			'token',
			__( 'Call the prepare tool again, show the user the summary and wait for a clear yes.', 'causeway-claude-bridge' )
		);
	}
}
