<?php
/**
 * `ccb/upload-image` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Media\ImagePipeline;
use WP_Error;

/**
 * Uploads an image sent as base64 through the image pipeline, optionally attaching it to a post
 * and setting it as the featured image.
 */
final class UploadImage extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/upload-image';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Upload image', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Uploads a JPEG, PNG or WebP image (base64) to the Media Library. Alt text is required: ask the user for a short description. Location and camera data are removed and the image is converted to WebP. With post_id it is attached to that post; set_featured makes it the featured image, which must meet the section\'s size and shape rules. Images in the body must be at least 800px wide; put the returned URL in the content. For files over the size limit, or if sending fails, use get-upload-link instead.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'upload_image';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'filename', 'data_base64', 'alt' ),
			'properties'           => array(
				'filename'     => array(
					'type'        => 'string',
					'maxLength'   => 255,
					'description' => __( 'Original file name (for reference only; the site names the file).', 'causeway-claude-bridge' ),
				),
				'data_base64'  => array(
					'type'        => 'string',
					'minLength'   => 4,
					'maxLength'   => 20000000,
					'description' => __( 'The image file, base64-encoded.', 'causeway-claude-bridge' ),
				),
				'alt'          => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 125,
				),
				'caption'      => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'post_id'      => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'set_featured' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'attachment_id', 'url', 'width', 'height', 'bytes', 'format' ),
			'properties' => array(
				'attachment_id' => array( 'type' => 'integer' ),
				'url'           => array( 'type' => 'string' ),
				'width'         => array( 'type' => 'integer' ),
				'height'        => array( 'type' => 'integer' ),
				'bytes'         => array( 'type' => 'integer' ),
				'format'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param AbilityContext       $context Request context.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input, AbilityContext $context ): array|WP_Error {
		$sections = new Sections();
		$alt      = trim( sanitize_text_field( (string) $input['alt'] ) );
		if ( '' === $alt ) {
			return AbilityError::make( 'ccb_invalid_input', __( 'Alt text is required.', 'causeway-claude-bridge' ), 400, 'alt', __( 'Ask the user for a short description of the image.', 'causeway-claude-bridge' ) );
		}
		$featured = ! empty( $input['set_featured'] );
		$post_id  = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
		$section  = array();

		if ( $post_id > 0 ) {
			$found = $sections->post( $post_id );
			if ( $found instanceof WP_Error ) {
				return $found;
			}
			[ $post, $key ] = $found;
			$context->about_post( $post, $key );
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return AbilityError::make( 'ccb_forbidden', __( 'You can\'t edit this post.', 'causeway-claude-bridge' ), 403, 'post_id' );
			}
			$section = $sections->all()[ $key ];
		} elseif ( $featured ) {
			return AbilityError::make( 'ccb_invalid_input', __( 'set_featured needs a post_id.', 'causeway-claude-bridge' ), 400, 'post_id' );
		}

		$pipeline = new ImagePipeline( $sections->config()['media'] );
		$path     = $pipeline->decode( (string) $input['data_base64'] );
		if ( $path instanceof WP_Error ) {
			return $path;
		}
		$image = $pipeline->process( $path, $pipeline->max_bytes(), $featured ? ImagePipeline::PURPOSE_FEATURED : ImagePipeline::PURPOSE_CONTENT, $section, 'data_base64' );
		if ( $image instanceof WP_Error ) {
			return $image;
		}

		$id = $pipeline->import( $image, $post_id, $alt, sanitize_textarea_field( (string) ( $input['caption'] ?? '' ) ), 'ccb_upload' );
		if ( $id instanceof WP_Error ) {
			return $id;
		}
		$context->attachment_id = $id;
		if ( $featured ) {
			set_post_thumbnail( $post_id, $id );
		}
		$context->details = array(
			'featured' => $featured,
			'format'   => $image['extension'],
			'bytes'    => $image['bytes'],
		);

		return array(
			'attachment_id' => $id,
			'url'           => (string) wp_get_attachment_url( $id ),
			'width'         => $image['width'],
			'height'        => $image['height'],
			'bytes'         => $image['bytes'],
			'format'        => $image['extension'],
		);
	}
}
