<?php
/**
 * `ccb/list-posts` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Content\PostPresenter;
use WP_Error;
use WP_Post;
use WP_Query;

/**
 * Posts in one section (or all sections), filtered by status, search text or author.
 */
final class ListPosts extends AbstractAbility {

	private const STATUSES         = array( 'draft', 'pending', 'future', 'publish', 'trash' );
	private const DEFAULT_STATUSES = array( 'draft', 'pending', 'future', 'publish' );

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/list-posts';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'List posts', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Lists posts in a section, newest first, filtered by status (draft, pending, future, publish, trash), search text, or only your own. Up to 20 per page. Use it to find a post\'s ID, or to check what happened after an error.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'list';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'section'  => array(
					'type'        => 'string',
					'description' => __( 'Section key. Omit for all sections.', 'causeway-claude-bridge' ),
					'pattern'     => '^[a-z][a-z0-9-]{1,30}$',
				),
				'status'   => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => self::STATUSES,
					),
					'uniqueItems' => true,
					'description' => __( 'Statuses to include. Default: everything except the Bin.', 'causeway-claude-bridge' ),
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'mine'     => array(
					'type'    => 'boolean',
					'default' => false,
				),
				'page'     => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 1000,
					'default' => 1,
				),
				'per_page' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 20,
					'default' => 10,
				),
			),
			'additionalProperties' => false,
			'default'              => array(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return array(
			'type'       => 'object',
			'required'   => array( 'total', 'items' ),
			'properties' => array(
				'total' => array( 'type' => 'integer' ),
				'items' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
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

		if ( isset( $input['section'] ) ) {
			$section = $sections->get( (string) $input['section'] );
			if ( $section instanceof WP_Error ) {
				return $section;
			}
			$context->section = (string) $input['section'];
			$post_types       = array( $section['post_type'] );
		} else {
			$post_types = array_column( $sections->all(), 'post_type' );
		}

		if ( array() === $post_types ) {
			return array(
				'total' => 0,
				'items' => array(),
			);
		}

		$args = array(
			'post_type'              => $post_types,
			'post_status'            => isset( $input['status'] ) && array() !== $input['status'] ? array_values( (array) $input['status'] ) : self::DEFAULT_STATUSES,
			'posts_per_page'         => (int) ( $input['per_page'] ?? 10 ),
			'paged'                  => (int) ( $input['page'] ?? 1 ),
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( ! empty( $input['search'] ) ) {
			$args['s'] = (string) $input['search'];
		}
		if ( ! empty( $input['mine'] ) ) {
			$args['author'] = get_current_user_id();
		}

		$query = new WP_Query( $args );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$key = $sections->key_for_post( $post );
			if ( null !== $key ) {
				$items[] = PostPresenter::summary( $post, $key );
			}
		}

		$context->details = array(
			'filters' => array_intersect_key( $input, array_flip( array( 'section', 'status', 'mine', 'page' ) ) ),
			'count'   => count( $items ),
		);

		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}
}
