<?php
/**
 * Post output for abilities (docs/03 `ccb/list-posts`, `ccb/get-post`).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

use WP_Post;

/**
 * Turns posts into the arrays abilities return.
 */
final class PostPresenter {

	/**
	 * Short form used in lists.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $section Section key.
	 * @return array<string, mixed>
	 */
	public static function summary( WP_Post $post, string $section ): array {
		return array(
			'id'           => $post->ID,
			'title'        => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
			'section'      => $section,
			'status'       => $post->post_status,
			'author'       => self::author( $post ),
			'date_gmt'     => self::iso( $post, 'date' ),
			'modified_gmt' => self::iso( $post, 'modified' ),
			'preview_url'  => self::preview_url( $post ),
			'live_url'     => self::live_url( $post ),
		);
	}

	/**
	 * Everything needed to edit a post.
	 *
	 * @param WP_Post              $post      Post.
	 * @param string               $section   Section key.
	 * @param array<string, mixed> $guide     The section's publishing guide entry (fields, taxonomies).
	 * @param array<string, mixed> $config    Config.
	 * @return array<string, mixed>
	 */
	public static function full( WP_Post $post, string $section, array $guide, array $config ): array {
		$summary = self::summary( $post, $section );

		$terms = array();
		foreach ( array_keys( $guide['taxonomies'] ) as $taxonomy ) {
			$assigned           = wp_get_object_terms( $post->ID, $taxonomy );
			$terms[ $taxonomy ] = is_array( $assigned ) ? array_map(
				static fn ( \WP_Term $term ): array => array(
					'id'   => $term->term_id,
					'name' => html_entity_decode( $term->name, ENT_QUOTES ),
				),
				$assigned
			) : array();
		}

		$fields = array();
		foreach ( $guide['fields'] as $field ) {
			$fields[ $field['name'] ] = self::field_value( $post->ID, $field );
		}

		$seo     = null;
		$seokeys = SeoFields::keys( $config );
		if ( null !== $seokeys ) {
			$seo = array(
				'title'       => (string) get_post_meta( $post->ID, $seokeys['title'], true ),
				'description' => (string) get_post_meta( $post->ID, $seokeys['description'], true ),
			);
		}

		$images = array();
		foreach ( get_attached_media( 'image', $post->ID ) as $attachment ) {
			$images[] = self::image( $attachment->ID );
		}

		$thumbnail = (int) get_post_thumbnail_id( $post );

		return array(
			'id'               => $post->ID,
			'section'          => $section,
			'status'           => $post->post_status,
			'title'            => $summary['title'],
			'content'          => $post->post_content,
			'excerpt'          => $post->post_excerpt,
			'slug'             => $post->post_name,
			'author'           => $summary['author'],
			'date_gmt'         => $summary['date_gmt'],
			'date_display'     => self::display( $post, 'date' ),
			'modified_gmt'     => $summary['modified_gmt'],
			'modified_display' => self::display( $post, 'modified' ),
			'terms'            => $terms,
			'fields'           => $fields,
			'featured_image'   => $thumbnail > 0 ? self::image( $thumbnail ) : null,
			'attached_images'  => $images,
			'seo'              => $seo,
			'preview_url'      => $summary['preview_url'],
			'live_url'         => $summary['live_url'],
			'elementor_built'  => 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ),
		);
	}

	/**
	 * A field's current value, in the shape Claude sends it back.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $field   Field from the guide.
	 */
	private static function field_value( int $post_id, array $field ): mixed {
		if ( 'meta' === ( $field['source'] ?? '' ) ) {
			$raw = get_post_meta( $post_id, $field['name'], true );
			if ( 'uk_postcode_to_latlng' === $field['type'] ) {
				$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
				return is_array( $decoded ) && isset( $decoded['lat'], $decoded['lng'] ) ? array(
					'lat' => (float) $decoded['lat'],
					'lng' => (float) $decoded['lng'],
				) : null;
			}
			return '' === $raw ? null : $raw;
		}
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $field['name'], $post_id, false );
			return ( null === $value || '' === $value || false === $value ) && 'true_false' !== $field['type'] ? null : $value;
		}
		return null;
	}

	/**
	 * Image details.
	 *
	 * @param int $attachment_id Attachment.
	 * @return array{id: int, url: string, alt: string}
	 */
	private static function image( int $attachment_id ): array {
		return array(
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_url( $attachment_id ),
			'alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Author ID and display name.
	 *
	 * @param WP_Post $post Post.
	 * @return array{id: int, name: string}
	 */
	private static function author( WP_Post $post ): array {
		$user = get_userdata( (int) $post->post_author );
		return array(
			'id'   => (int) $post->post_author,
			'name' => false === $user ? '' : $user->display_name,
		);
	}

	/**
	 * Preview link for posts that aren't live; null otherwise.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function preview_url( WP_Post $post ): ?string {
		if ( in_array( $post->post_status, array( 'publish', 'trash' ), true ) ) {
			return null;
		}
		return get_preview_post_link( $post );
	}

	/**
	 * Public URL for published posts; null otherwise.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function live_url( WP_Post $post ): ?string {
		if ( 'publish' !== $post->post_status ) {
			return null;
		}
		return get_permalink( $post );
	}

	/**
	 * ISO 8601 UTC. Drafts have no GMT date in the database; WordPress works it out from the local date.
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $field `date` or `modified`.
	 */
	public static function iso( WP_Post $post, string $field ): ?string {
		$timestamp = get_post_timestamp( $post, $field );
		return false === $timestamp ? null : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Date in the site's timezone and format.
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $field `date` or `modified`.
	 */
	public static function display( WP_Post $post, string $field ): ?string {
		$timestamp = get_post_timestamp( $post, $field );
		if ( false === $timestamp ) {
			return null;
		}
		$formatted = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		return false === $formatted ? null : $formatted;
	}
}
