<?php
/**
 * Activation, deactivation and upgrades (docs/06-data-model.md).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Db\Schema;
use Causeway\ClaudeBridge\Roles\RoleManager;
use DateTimeImmutable;

/**
 * Sets the plugin up on activation and keeps it up to date after plugin updates.
 */
final class Activation {

	/**
	 * Plugin version the site was last set up for; compared with CCB_VERSION to run upgrades.
	 */
	public const OPTION_INSTALLED_VERSION = 'ccb_installed_version';

	public const CRON_HEALTH = 'ccb_daily_health';
	public const CRON_PRUNE  = 'ccb_daily_prune';

	/**
	 * Daily cron times in site time (docs/06).
	 */
	private const CRON_TIMES = array(
		self::CRON_HEALTH => '03:15',
		self::CRON_PRUNE  => '03:45',
	);

	/**
	 * Runs on activation, after the requirement checks in the main plugin file have passed.
	 */
	public static function activate(): void {
		self::install( get_current_user_id() );
	}

	/**
	 * Runs on deactivation. Keeps data and roles; removing the cron jobs is all that's needed.
	 * The MCP server stops existing with the plugin, so Claude loses access immediately.
	 */
	public static function deactivate(): void {
		foreach ( array_keys( self::CRON_TIMES ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Re-runs the idempotent setup after a plugin update (activation hooks don't fire on updates).
	 */
	public static function maybe_upgrade(): void {
		if ( CCB_VERSION === get_option( self::OPTION_INSTALLED_VERSION ) && ! Schema::needs_upgrade() ) {
			return;
		}
		self::install( 0 );
	}

	/**
	 * Tables, roles, default config and cron. Safe to run any number of times.
	 *
	 * @param int $user_id User to record against the default config (0 for upgrades).
	 */
	private static function install( int $user_id ): void {
		Schema::install();
		RoleManager::sync();
		( new ConfigRepository() )->ensure_default( $user_id );
		self::schedule_cron();
		update_option( self::OPTION_INSTALLED_VERSION, CCB_VERSION, true );
	}

	/**
	 * Schedules the daily jobs at their site-time slots if they aren't scheduled already.
	 */
	private static function schedule_cron(): void {
		foreach ( self::CRON_TIMES as $hook => $time ) {
			if ( false === wp_next_scheduled( $hook ) ) {
				wp_schedule_event( self::next_occurrence( $time ), 'daily', $hook );
			}
		}
	}

	/**
	 * Unix timestamp of the next HH:MM in the site's timezone.
	 *
	 * @param string $time Time as HH:MM.
	 */
	public static function next_occurrence( string $time ): int {
		$timezone          = wp_timezone();
		$now               = new DateTimeImmutable( 'now', $timezone );
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time ) );
		$next              = $now->setTime( $hour, $minute );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
		return $next->getTimestamp();
	}
}
