<?php
/**
 * Per-user rate limits (docs/02 "Limits").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Security;

/**
 * Counts requests per user per minute, and live changes per user per day. Uses the persistent object
 * cache when there is one, otherwise transients.
 */
final class RateLimiter {

	private const GROUP = 'ccb';

	/**
	 * Counts a request and says whether it's within the per-minute limit.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Requests allowed per minute.
	 * @return int 0 when allowed, otherwise seconds until the next minute starts.
	 */
	public static function hit_request( int $user_id, int $limit ): int {
		$now    = time();
		$minute = (int) floor( $now / 60 );
		$count  = self::increment( "ccb_rl_{$user_id}_{$minute}", 2 * MINUTE_IN_SECONDS );
		return $count > $limit ? 60 - ( $now % 60 ) : 0;
	}

	/**
	 * Says whether a live change is within the daily cap, without counting it.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Live changes allowed per day.
	 * @return int 0 when allowed, otherwise seconds until midnight UTC.
	 */
	public static function check_live_change( int $user_id, int $limit ): int {
		$count = (int) self::get( self::daily_key( $user_id ) );
		return $count >= $limit ? DAY_IN_SECONDS - ( time() % DAY_IN_SECONDS ) : 0;
	}

	/**
	 * Counts a live change that went ahead.
	 *
	 * @param int $user_id User.
	 */
	public static function record_live_change( int $user_id ): void {
		self::increment( self::daily_key( $user_id ), 2 * DAY_IN_SECONDS );
	}

	/**
	 * Key for today's live-change counter.
	 *
	 * @param int $user_id User.
	 */
	private static function daily_key( int $user_id ): string {
		return 'ccb_daily_' . $user_id . '_' . gmdate( 'Ymd' );
	}

	/**
	 * Current value of a counter.
	 *
	 * @param string $key Key.
	 */
	private static function get( string $key ): int {
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, self::GROUP );
		}
		return (int) get_transient( $key );
	}

	/**
	 * Adds one to a counter and returns the new value.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Lifetime in seconds.
	 */
	private static function increment( string $key, int $ttl ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::GROUP, $ttl );
			$value = wp_cache_incr( $key, 1, self::GROUP );
			return false === $value ? 1 : (int) $value;
		}
		$value = (int) get_transient( $key ) + 1;
		set_transient( $key, $value, $ttl );
		return $value;
	}
}
