<?php
/**
 * Post verification (docs/07 "2. Post verification").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Checks;

use Causeway\ClaudeBridge\Content\ContentSanitiser;
use WP_Post;

/**
 * Re-reads a post from the database after a write and returns the checklist Claude shows the user.
 * Failed checks carry a fix; nothing is undone, so work isn't lost.
 *
 * Database checks only; the live-URL HTTP check and cache purge come with M7.
 */
final class PostVerifier {

	/**
	 * Checks a post.
	 *
	 * @param int                                  $post_id       Post.
	 * @param array<string, mixed>                 $section       Section config.
	 * @param array<string, mixed>                 $guide_section Section entry from the publishing guide.
	 * @param array<string, mixed>                 $writing       Config `writing` block.
	 * @param array{status?: string, author?: int} $expected Expected values.
	 * @return array{passed: bool, checks: list<array{name: string, passed: bool, message: string, fix?: string}>}
	 */
	public static function verify( int $post_id, array $section, array $guide_section, array $writing, array $expected = array() ): array {
		clean_post_cache( $post_id );
		$post   = get_post( $post_id );
		$checks = array();

		if ( ! $post instanceof WP_Post ) {
			return array(
				'passed' => false,
				'checks' => array( self::fail( 'exists', __( 'The post couldn\'t be found after saving.', 'causeway-claude-bridge' ), __( 'Check with get-post or list-posts.', 'causeway-claude-bridge' ) ) ),
			);
		}
		$checks[] = self::pass( 'exists', __( 'Post saved.', 'causeway-claude-bridge' ) );

		if ( isset( $expected['status'] ) ) {
			$checks[] = $post->post_status === $expected['status']
				/* translators: %s: post status */
				? self::pass( 'status', sprintf( __( 'Status is %s.', 'causeway-claude-bridge' ), self::status_label( $post->post_status ) ) )
				/* translators: 1: actual status, 2: expected status */
				: self::fail( 'status', sprintf( __( 'Status is %1$s, expected %2$s.', 'causeway-claude-bridge' ), self::status_label( $post->post_status ), self::status_label( $expected['status'] ) ), __( 'Tell the site\'s support contact.', 'causeway-claude-bridge' ) );
		}
		if ( isset( $expected['author'] ) ) {
			$checks[] = (int) $post->post_author === $expected['author']
				? self::pass( 'author', __( 'Author is you.', 'causeway-claude-bridge' ) )
				: self::fail( 'author', __( 'The author isn\'t what was expected.', 'causeway-claude-bridge' ), __( 'Tell the site\'s support contact.', 'causeway-claude-bridge' ) );
		}

		$checks[] = $post->post_type === $section['post_type']
			? self::pass( 'post_type', __( 'In the right section.', 'causeway-claude-bridge' ) )
			: self::fail( 'post_type', __( 'The post is in the wrong section.', 'causeway-claude-bridge' ), __( 'Tell the site\'s support contact.', 'causeway-claude-bridge' ) );

		// Terms.
		foreach ( $guide_section['taxonomies'] as $taxonomy => $info ) {
			$assigned = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			$count    = is_array( $assigned ) ? count( $assigned ) : 0;
			if ( $info['required'] ) {
				$checks[] = $count > 0
					/* translators: %s: taxonomy label */
					? self::pass( 'terms_' . $taxonomy, sprintf( __( '%s chosen.', 'causeway-claude-bridge' ), $info['label'] ) )
					/* translators: %s: taxonomy label */
					: self::fail( 'terms_' . $taxonomy, sprintf( __( 'No %s chosen. It\'s required before publishing.', 'causeway-claude-bridge' ), $info['label'] ), __( 'Choose one from the publishing guide and update the post.', 'causeway-claude-bridge' ) );
			}
		}

		// Fields.
		foreach ( $guide_section['fields'] as $field ) {
			$value   = self::field_value( $post_id, $field );
			$present = null !== $value && '' !== $value && array() !== $value;
			if ( 'uk_postcode_to_latlng' === $field['type'] && $present ) {
				$decoded  = json_decode( (string) $value, true );
				$ok       = is_array( $decoded ) && isset( $decoded['lat'], $decoded['lng'] ) && is_numeric( $decoded['lat'] ) && is_numeric( $decoded['lng'] );
				$checks[] = $ok
					/* translators: %s: field label */
					? self::pass( 'field_' . $field['name'], sprintf( __( '%s set.', 'causeway-claude-bridge' ), $field['label'] ) )
					/* translators: %s: field label */
					: self::fail( 'field_' . $field['name'], sprintf( __( '%s isn\'t stored correctly.', 'causeway-claude-bridge' ), $field['label'] ), __( 'Send the postcode again.', 'causeway-claude-bridge' ) );
				continue;
			}
			if ( 'required' === $field['rule'] ) {
				$checks[] = $present
					/* translators: %s: field label */
					? self::pass( 'field_' . $field['name'], sprintf( __( '%s set.', 'causeway-claude-bridge' ), $field['label'] ) )
					/* translators: %s: field label */
					: self::fail( 'field_' . $field['name'], sprintf( __( '%s is missing. It\'s required before publishing.', 'causeway-claude-bridge' ), $field['label'] ), __( 'Ask the user for it and update the post.', 'causeway-claude-bridge' ) );
			}
		}

		// Featured image.
		$thumbnail = (int) get_post_thumbnail_id( $post );
		if ( $thumbnail > 0 ) {
			$file     = get_attached_file( $thumbnail );
			$alt      = (string) get_post_meta( $thumbnail, '_wp_attachment_image_alt', true );
			$checks[] = is_string( $file ) && file_exists( $file )
				? self::pass( 'featured_image', __( 'Featured image attached.', 'causeway-claude-bridge' ) )
				: self::fail( 'featured_image', __( 'The featured image file is missing on the server.', 'causeway-claude-bridge' ), __( 'Upload the image again.', 'causeway-claude-bridge' ) );
			$checks[] = '' !== trim( $alt )
				? self::pass( 'featured_image_alt', __( 'Featured image has alt text.', 'causeway-claude-bridge' ) )
				: self::fail( 'featured_image_alt', __( 'The featured image has no alt text.', 'causeway-claude-bridge' ), __( 'Ask for a short description of the image and upload it again with alt text.', 'causeway-claude-bridge' ) );
		} elseif ( ! empty( $section['featured_image']['required'] ) ) {
			$checks[] = self::fail( 'featured_image', __( 'Featured image missing. It\'s required before publishing.', 'causeway-claude-bridge' ), __( 'Ask for an image, or use an upload link.', 'causeway-claude-bridge' ) );
		}

		// Content.
		$limits = $section['limits'];
		$words  = ContentSanitiser::word_count( $post->post_content );
		if ( $limits['max_words'] > 0 && $words > $limits['max_words'] ) {
			/* translators: 1: word count, 2: maximum */
			$checks[] = self::fail( 'word_count', sprintf( __( '%1$d words, more than the %2$d allowed.', 'causeway-claude-bridge' ), $words, $limits['max_words'] ), __( 'Shorten the post.', 'causeway-claude-bridge' ) );
		} elseif ( $limits['min_words'] > 0 && $words < $limits['min_words'] ) {
			/* translators: 1: word count, 2: minimum */
			$checks[] = self::fail( 'word_count', sprintf( __( '%1$d words, fewer than the %2$d suggested.', 'causeway-claude-bridge' ), $words, $limits['min_words'] ), __( 'Consider adding more detail.', 'causeway-claude-bridge' ) );
		} else {
			/* translators: %d: word count */
			$checks[] = self::pass( 'word_count', sprintf( __( '%d words.', 'causeway-claude-bridge' ), $words ) );
		}

		$images = substr_count( strtolower( $post->post_content ), '<img' );
		if ( $limits['max_images'] > 0 ) {
			$checks[] = $images <= $limits['max_images']
				/* translators: %d: image count */
				? self::pass( 'image_count', sprintf( __( '%d images in the post.', 'causeway-claude-bridge' ), $images ) )
				/* translators: 1: image count, 2: maximum */
				: self::fail( 'image_count', sprintf( __( '%1$d images, more than the %2$d allowed.', 'causeway-claude-bridge' ), $images, $limits['max_images'] ), __( 'Remove some images.', 'causeway-claude-bridge' ) );
		}

		$bad_headings = array_diff( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), (array) $writing['headings'] );
		$has_bad      = false;
		foreach ( $bad_headings as $tag ) {
			if ( false !== stripos( $post->post_content, '<' . $tag ) ) {
				$has_bad = true;
			}
		}
		$checks[] = $has_bad
			? self::fail( 'headings', __( 'The content has headings that aren\'t allowed.', 'causeway-claude-bridge' ), __( 'Update the post; headings are fixed automatically when content is sent again.', 'causeway-claude-bridge' ) )
			: self::pass( 'headings', __( 'Headings are allowed ones.', 'causeway-claude-bridge' ) );

		if ( in_array( $post->post_status, array( 'draft', 'pending' ), true ) ) {
			$preview  = get_preview_post_link( $post );
			$checks[] = '' !== $preview
				? self::pass( 'preview', __( 'Preview link ready.', 'causeway-claude-bridge' ) )
				: self::fail( 'preview', __( 'No preview link.', 'causeway-claude-bridge' ), __( 'Open the post in WP Admin to preview it.', 'causeway-claude-bridge' ) );
		}

		return array(
			'passed' => ! in_array( false, array_column( $checks, 'passed' ), true ),
			'checks' => $checks,
		);
	}

	/**
	 * Current value of a field.
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $field   Guide field.
	 */
	private static function field_value( int $post_id, array $field ): mixed {
		if ( 'meta' === $field['source'] ) {
			return get_post_meta( $post_id, $field['name'], true );
		}
		return function_exists( 'get_field' ) ? get_field( $field['name'], $post_id, false ) : null;
	}

	/**
	 * Readable status.
	 *
	 * @param string $status Status.
	 */
	private static function status_label( string $status ): string {
		$object = get_post_status_object( $status );
		return null === $object ? $status : (string) $object->label;
	}

	/**
	 * A passed check.
	 *
	 * @param string $name    Check name.
	 * @param string $message Message.
	 * @return array{name: string, passed: bool, message: string}
	 */
	private static function pass( string $name, string $message ): array {
		return array(
			'name'    => $name,
			'passed'  => true,
			'message' => $message,
		);
	}

	/**
	 * A failed check.
	 *
	 * @param string $name    Check name.
	 * @param string $message Message.
	 * @param string $fix     Fix.
	 * @return array{name: string, passed: bool, message: string, fix: string}
	 */
	private static function fail( string $name, string $message, string $fix ): array {
		return array(
			'name'    => $name,
			'passed'  => false,
			'message' => $message,
			'fix'     => $fix,
		);
	}
}
