<?php
/**
 * Uninstall (docs/06-data-model.md, "Uninstall").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge;

use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Roles\RoleManager;

/**
 * Removes the plugin's data, but only when an administrator asked for that.
 * Posts, media and field values are never deleted.
 */
final class Uninstaller {

	/**
	 * Set from the Advanced tab ("Delete all data on uninstall", default off).
	 */
	public const OPTION_DELETE_DATA = 'ccb_delete_data_on_uninstall';

	/**
	 * Runs the uninstall.
	 */
	public static function run(): void {
		foreach ( array( Activation::CRON_HEALTH, Activation::CRON_PRUNE ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		if ( ! get_option( self::OPTION_DELETE_DATA, false ) ) {
			return;
		}

		RoleManager::remove_all();
		Schema::drop_all();
		self::delete_options_and_meta();
	}

	/**
	 * Deletes `ccb_*` options and transients and `_ccb_*` post meta.
	 */
	private static function delete_options_and_meta(): void {
		global $wpdb;

		$like_option    = $wpdb->esc_like( 'ccb_' ) . '%';
		$like_transient = $wpdb->esc_like( '_transient_ccb_' ) . '%';
		$like_timeout   = $wpdb->esc_like( '_transient_timeout_ccb_' ) . '%';
		$like_meta      = $wpdb->esc_like( '_ccb_' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$like_option,
				$like_transient,
				$like_timeout
			)
		);
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $like_meta ) );
		// phpcs:enable

		wp_cache_flush();
	}
}
