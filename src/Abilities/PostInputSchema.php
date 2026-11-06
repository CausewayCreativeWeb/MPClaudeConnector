<?php
/**
 * Shared input schema for create-post and update-post.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

/**
 * Post fields Claude can send. Detailed rules (terms, fields, limits) come from the publishing guide
 * and are checked by the plugin, not by this schema.
 */
final class PostInputSchema {

	/**
	 * Properties shared by create and update.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties(): array {
		return array(
			'title'             => array(
				'type'        => 'string',
				'description' => __( 'Plain-text title.', 'causeway-claude-bridge' ),
				'maxLength'   => 400,
			),
			'content'           => array(
				'type'        => 'string',
				'description' => __( 'Body as HTML, using only the allowed tags and headings from the publishing guide.', 'causeway-claude-bridge' ),
				'maxLength'   => 200000,
			),
			'excerpt'           => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
			'slug'              => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'terms'             => array(
				'type'                 => 'object',
				'description'          => __( 'Taxonomy => list of existing term IDs or slugs. New terms are never created.', 'causeway-claude-bridge' ),
				'additionalProperties' => array(
					'type'  => 'array',
					'items' => array( 'type' => array( 'integer', 'string' ) ),
				),
			),
			'fields'            => array(
				'type'        => 'object',
				'description' => __( 'Field name => value, as listed in the publishing guide. Null clears a field.', 'causeway-claude-bridge' ),
			),
			'featured_image_id' => array(
				'type'        => 'integer',
				'minimum'     => 0,
				'description' => __( 'Attachment ID from upload-image. 0 removes the featured image.', 'causeway-claude-bridge' ),
			),
			'seo'               => array(
				'type'                 => 'object',
				'properties'           => array(
					'title'       => array(
						'type'      => 'string',
						'maxLength' => 400,
					),
					'description' => array(
						'type'      => 'string',
						'maxLength' => 1000,
					),
				),
				'additionalProperties' => false,
			),
			'status'            => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'publish', 'future' ),
				'description' => __( 'draft or pending (AI Publishers only; AI Contributors\' posts are always pending). publish and future are refused here: use prepare-publish or prepare-schedule.', 'causeway-claude-bridge' ),
			),
		);
	}

	/**
	 * Output schema for create and update.
	 *
	 * @return array<string, mixed>
	 */
	public static function output(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'post_id', 'status', 'verification', 'warnings' ),
			'properties' => array(
				'post_id'      => array( 'type' => 'integer' ),
				'status'       => array( 'type' => 'string' ),
				'preview_url'  => array( 'type' => array( 'string', 'null' ) ),
				'edit_url'     => array( 'type' => 'string' ),
				'verification' => array(
					'type'       => 'object',
					'properties' => array(
						'passed' => array( 'type' => 'boolean' ),
						'checks' => array( 'type' => 'array' ),
					),
				),
				'warnings'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
		);
	}
}
