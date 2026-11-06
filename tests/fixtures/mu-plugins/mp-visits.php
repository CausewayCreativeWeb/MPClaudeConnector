<?php
/**
 * Test double for the MP Visits 1.3 plugin (docs/reference/mp-visits-findings.md).
 * Registers the same post type and image size. The real plugin is never changed.
 *
 * @package Causeway\ClaudeBridge
 */

add_image_size( 'map-thumb', 250, 140, array( 'center', 'center' ) );

add_action(
	'init',
	static function (): void {
		register_post_type(
			'visits',
			array(
				'label'           => 'Visit',
				'labels'          => array(
					'name'          => 'Visits',
					'singular_name' => 'Visit',
				),
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'hierarchical'    => false,
				'public'          => true,
				'show_ui'         => true,
				'has_archive'     => true,
				'capability_type' => 'post',
			)
		);
	},
	0
);

/**
 * Replica of MP Visits' save_visits_meta(): reads $_POST keys without isset(), returns early without
 * its nonce, and requires edit_post AND edit_page. The real plugin raises PHP "undefined array key"
 * warnings when a post is saved in code; here they're counted instead (ccb_fixture_visits_warnings),
 * so tests can assert they happen without PHPUnit turning them into failures.
 *
 * @param int $post_id Post ID.
 */
function ccb_fixture_save_visits_meta( $post_id ) {
	foreach ( array( 'visits_meta_box_nonce' ) as $key ) {
		if ( ! array_key_exists( $key, $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$GLOBALS['ccb_fixture_visits_warnings'] = ( $GLOBALS['ccb_fixture_visits_warnings'] ?? 0 ) + 1;
		}
	}
	if ( ! wp_verify_nonce( $_POST['visits_meta_box_nonce'] ?? '', 'mp-visits.php' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		return $post_id;
	}
	if ( 'visits' === ( $_POST['post_type'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- replica of the real plugin's code.
		if ( ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_page', $post_id ) ) {
			return $post_id;
		}
	}
	$old = get_post_meta( $post_id, 'visits_location', true );
	$new = $_POST['visits_location'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
	if ( $new && $new !== $old ) {
		update_post_meta( $post_id, 'visits_location', $new );
	} elseif ( '' === $new && $old ) {
		delete_post_meta( $post_id, 'visits_location', $old );
	}
	return $post_id;
}
add_action( 'save_post', 'ccb_fixture_save_visits_meta' );

/**
 * Replica of the [visits_map] shortcode's data: every visits post with a location, decoded the way
 * the real plugin does. Returns JSON of the locations instead of the Leaflet markup.
 */
add_shortcode(
	'visits_map',
	static function (): string {
		$query     = new WP_Query(
			array(
				'post_type'      => 'visits',
				'posts_per_page' => -1,
			)
		);
		$locations = array();
		foreach ( $query->posts as $post ) {
			$location = get_post_meta( $post->ID, 'visits_location', true );
			if ( $location ) {
				$locations[] = array(
					'title'     => get_the_title( $post ),
					'thumbnail' => get_the_post_thumbnail_url( $post->ID, array( 250, 140 ) ),
					'location'  => json_decode( $location, true ),
				);
			}
		}
		return (string) wp_json_encode( $locations );
	}
);
