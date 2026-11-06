<?php
/**
 * Idempotency keys for create-post (docs/03 create-post rule 9; docs/06 `ccb_idempotency`).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

use Causeway\ClaudeBridge\Db\Schema;

/**
 * If Claude retries a create with a key it already used in the last 24 hours, the original result
 * is returned instead of a second post.
 */
final class Idempotency {

	public const WINDOW = DAY_IN_SECONDS;

	/**
	 * Stored response for a key, if seen in the window.
	 *
	 * @param int    $user_id User.
	 * @param string $key     Key.
	 * @return array<string, mixed>|null
	 */
	public static function find( int $user_id, string $key ): ?array {
		global $wpdb;
		$table = Schema::table( Schema::IDEMPOTENCY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, name from constants.
		$json = $wpdb->get_var( $wpdb->prepare( "SELECT response FROM `{$table}` WHERE user_id = %d AND idem_key = %s AND created_at_gmt >= %s", $user_id, $key, gmdate( 'Y-m-d H:i:s', time() - self::WINDOW ) ) );
		if ( ! is_string( $json ) ) {
			return null;
		}
		$response = json_decode( $json, true );
		return is_array( $response ) ? $response : null;
	}

	/**
	 * Stores the response for a key (replacing an expired one).
	 *
	 * @param int                  $user_id  User.
	 * @param string               $key      Key.
	 * @param int                  $post_id  Post created.
	 * @param array<string, mixed> $response Response returned to Claude.
	 */
	public static function store( int $user_id, string $key, int $post_id, array $response ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->replace(
			Schema::table( Schema::IDEMPOTENCY ),
			array(
				'user_id'        => $user_id,
				'idem_key'       => $key,
				'post_id'        => $post_id,
				'response'       => (string) wp_json_encode( $response ),
				'created_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}
}
