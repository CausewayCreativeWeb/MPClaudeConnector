<?php
/**
 * Live URL check after a live change (docs/07 "2. Post verification", last bullet).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Checks;

use WP_Error;

/**
 * After publishing or updating a live post its public URL must return 200; after unpublishing or
 * moving to the Bin it must not (404 or a redirect). Known page caches are purged first; otherwise
 * the check warns that cached copies may take a few minutes to update.
 */
final class LiveUrlCheck {

	/**
	 * Purges the post from known page caches. Returns whether any cache was purged.
	 *
	 * @param int $post_id Post.
	 */
	public static function purge( int $post_id ): bool {
		$purged = false;
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
			$purged = true;
		}
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $post_id );
			$purged = true;
		}
		if ( has_action( 'litespeed_purge_post' ) ) {
			do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache's own hook.
			$purged = true;
		}

		/**
		 * Lets other cache layers purge a post the Bridge just changed.
		 *
		 * @param bool $purged  Whether a cache was purged already.
		 * @param int  $post_id Post.
		 */
		return (bool) apply_filters( 'ccb_purge_post_cache', $purged, $post_id );
	}

	/**
	 * Checks the public URL.
	 *
	 * @param string $url       Public URL.
	 * @param bool   $should_be Whether the page should be live.
	 * @param bool   $purged    Whether a cache was purged.
	 * @return array{name: string, passed: bool, message: string, fix?: string}
	 */
	public static function check( string $url, bool $should_be, bool $purged ): array {
		$response   = wp_remote_get(
			add_query_arg( 'ccb_check', (string) time(), $url ),
			array(
				'timeout'     => 10,
				'sslverify'   => true,
				'redirection' => 0,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		$cache_note = $purged ? '' : ' ' . __( 'Cached copies may take a few minutes to update.', 'causeway-claude-bridge' );

		if ( $response instanceof WP_Error ) {
			return array(
				'name'    => 'live_url',
				'passed'  => false,
				'message' => __( 'The public page couldn\'t be checked from the server.', 'causeway-claude-bridge' ) . $cache_note,
				'fix'     => __( 'Open the link yourself to check it.', 'causeway-claude-bridge' ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $should_be ) {
			return 200 === $code
				? array(
					'name'    => 'live_url',
					'passed'  => true,
					'message' => __( 'The public page is live.', 'causeway-claude-bridge' ) . $cache_note,
				)
				: array(
					'name'    => 'live_url',
					'passed'  => false,
					/* translators: %d: HTTP status */
					'message' => sprintf( __( 'The public page returned HTTP %d instead of loading.', 'causeway-claude-bridge' ), $code ),
					'fix'     => __( 'Open the link to check it, and tell the site\'s support contact if it doesn\'t load.', 'causeway-claude-bridge' ),
				);
		}

		$gone = 404 === $code || 410 === $code || ( $code >= 300 && $code < 400 );
		return $gone
			? array(
				'name'    => 'live_url',
				'passed'  => true,
				'message' => __( 'The page is no longer public.', 'causeway-claude-bridge' ) . $cache_note,
			)
			: array(
				'name'    => 'live_url',
				'passed'  => false,
				/* translators: %d: HTTP status */
				'message' => sprintf( __( 'The old address still loads (HTTP %d), probably from a cache.', 'causeway-claude-bridge' ), $code ),
				'fix'     => __( 'Clear the site\'s page cache, or wait a few minutes.', 'causeway-claude-bridge' ),
			);
	}
}
