<?php
/**
 * `ccb/get-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Config\PublishingGuide;
use Causeway\ClaudeBridge\Content\PostPresenter;
use WP_Error;

/**
 * Everything needed to edit one post: content, terms, fields, images, SEO and links.
 */
final class GetPost extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/get-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Get post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Returns one post with everything needed to edit it: status, title, content, excerpt, terms, fields, featured image, SEO title and description, and preview or live links. "elementor_built" true means its content can only be edited in WP Admin. Also use it to confirm an upload link was used, or to check what happened after an error.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'get';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
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
			'required'   => array( 'id', 'section', 'status', 'title', 'content' ),
			'properties' => array(
				'id'              => array( 'type' => 'integer' ),
				'section'         => array( 'type' => 'string' ),
				'status'          => array( 'type' => 'string' ),
				'title'           => array( 'type' => 'string' ),
				'content'         => array( 'type' => 'string' ),
				'elementor_built' => array( 'type' => 'boolean' ),
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
		$found    = $sections->post( (int) $input['post_id'] );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		[ $post, $key ] = $found;
		$context->about_post( $post, $key );

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return AbilityError::make( 'ccb_forbidden', __( 'You can\'t edit this post.', 'causeway-claude-bridge' ), 403, 'post_id' );
		}

		$guide = PublishingGuide::build( $sections->config(), $sections->structure(), $key );
		return PostPresenter::full( $post, $key, $guide['sections'][0], $sections->config() );
	}
}
