<?php
/**
 * Activity log (docs/06 `ccb_activity`).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Audit;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;

/**
 * Writes one row per ability call (including refusals), admin change, role change and revoke.
 * Never stores credentials, tokens or full post content.
 */
final class ActivityLog {

	public const RESULTS = array( 'success', 'rejected', 'error' );

	/**
	 * Keys allowed in a row; anything else goes in `details`.
	 */
	private const COLUMNS = array( 'action', 'ability', 'post_id', 'attachment_id', 'section', 'post_title_snapshot', 'status_from', 'status_to', 'result', 'error_code', 'message' );

	/**
	 * Detail keys that must never be logged.
	 */
	private const SECRET_KEYS = array( 'token', 'password', 'app_password', 'authorization', 'content', 'data_base64' );

	/**
	 * Records an entry. Returns the row ID, or 0 if it couldn't be written (logging never breaks the action).
	 *
	 * @param array<string, mixed> $entry   Column values: action and result are required.
	 * @param array<string, mixed> $details Extra context stored as JSON.
	 */
	public static function record( array $entry, array $details = array() ): int {
		global $wpdb;

		$row = array_intersect_key( $entry, array_flip( self::COLUMNS ) );
		if ( empty( $row['action'] ) || ! in_array( $row['result'] ?? '', self::RESULTS, true ) ) {
			return 0;
		}

		$app = RequestContext::app_password();

		$row['created_at_gmt']      = gmdate( 'Y-m-d H:i:s' );
		$row['user_id']             = get_current_user_id();
		$row['app_password_uuid']   = $app['uuid'] ?? null;
		$row['app_password_name']   = null === $app ? null : mb_substr( $app['name'], 0, 191 );
		$row['config_version']      = ( new ConfigRepository() )->version();
		$row['ip']                  = RequestContext::ip_binary();
		$row['request_ref']         = (string) ( $entry['request_ref'] ?? RequestContext::request_ref() );
		$row['action']              = mb_substr( (string) $row['action'], 0, 40 );
		$row['message']             = isset( $row['message'] ) ? mb_substr( (string) $row['message'], 0, 500 ) : null;
		$row['post_title_snapshot'] = isset( $row['post_title_snapshot'] ) ? mb_substr( (string) $row['post_title_snapshot'], 0, 255 ) : null;
		$row['details']             = array() === $details ? null : wp_json_encode( self::redact( $details ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$inserted = $wpdb->insert( Schema::table( Schema::ACTIVITY ), $row );
		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Removes secret values from details, at any depth.
	 *
	 * @param array<mixed> $details Details.
	 * @return array<mixed>
	 */
	public static function redact( array $details ): array {
		foreach ( $details as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::SECRET_KEYS, true ) ) {
				$details[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$details[ $key ] = self::redact( $value );
			}
		}
		return $details;
	}
}
