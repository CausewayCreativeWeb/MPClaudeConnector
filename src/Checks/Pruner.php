<?php
/**
 * Daily clean-up (docs/06; cron `ccb_daily_prune`).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Checks;

use Causeway\ClaudeBridge\Activation;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Media\TempDir;
use Causeway\ClaudeBridge\Security\ConfirmationTokens;

/**
 * Deletes activity older than the retention period, expired tokens and upload links, idempotency
 * keys older than a day, and stray temp files.
 */
final class Pruner {

	/**
	 * Hooks the cron job.
	 */
	public static function register_hooks(): void {
		add_action( Activation::CRON_PRUNE, array( self::class, 'cron' ) );
	}

	/**
	 * Cron entry point.
	 */
	public static function cron(): void {
		self::run();
	}

	/**
	 * Runs the clean-up.
	 *
	 * @return array<string, int> Rows or files deleted per kind.
	 */
	public static function run(): array {
		global $wpdb;
		$months = (int) ( new ConfigRepository() )->get()['activity']['retention_months'];
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) strtotime( "-{$months} months" ) );
		$day    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$activity = Schema::table( Schema::ACTIVITY );
		$links    = Schema::table( Schema::UPLOAD_LINKS );
		$idem     = Schema::table( Schema::IDEMPOTENCY );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, names from constants.
		$counts = array(
			'activity'     => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$activity}` WHERE created_at_gmt < %s", $cutoff ) ),
			'tokens'       => ConfirmationTokens::prune(),
			'upload_links' => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$links}` WHERE expires_at_gmt < %s", $day ) ),
			'idempotency'  => (int) $wpdb->query( $wpdb->prepare( "DELETE FROM `{$idem}` WHERE created_at_gmt < %s", $day ) ),
			'temp_files'   => TempDir::prune( HOUR_IN_SECONDS ),
		);
		// phpcs:enable

		return $counts;
	}
}
