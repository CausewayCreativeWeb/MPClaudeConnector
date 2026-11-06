<?php
/**
 * `ccb/list-terms` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use WP_Error;
use WP_Term;

/**
 * Existing terms in one of a section's taxonomies. Claude may only choose from these.
 */
final class ListTerms extends AbstractAbility {

	private const MAX = 200;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/list-terms';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'List terms', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Lists existing categories, tags or other terms in one taxonomy of a section, optionally filtered by a search word. Only choose terms from this list; new terms are never created.', 'causeway-claude-bridge' );
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
			'required'             => array( 'section', 'taxonomy' ),
			'properties'           => array(
				'section'  => array(
					'type'    => 'string',
					'pattern' => '^[a-z][a-z0-9-]{1,30}$',
				),
				'taxonomy' => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9_-]{1,32}$',
				),
				'search'   => array(
					'type'      => 'string',
					'maxLength' => 100,
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
			'required'   => array( 'terms' ),
			'properties' => array(
				'terms' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'     => array( 'type' => 'integer' ),
							'slug'   => array( 'type' => 'string' ),
							'name'   => array( 'type' => 'string' ),
							'parent' => array( 'type' => 'integer' ),
							'count'  => array( 'type' => 'integer' ),
						),
					),
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
		$section  = $sections->get( (string) $input['section'] );
		if ( $section instanceof WP_Error ) {
			return $section;
		}
		$context->section = (string) $input['section'];

		$taxonomy = (string) $input['taxonomy'];
		$allowed  = get_object_taxonomies( $section['post_type'] );
		if ( ! in_array( $taxonomy, $allowed, true ) || 'post_format' === $taxonomy ) {
			return AbilityError::make(
				'ccb_invalid_input',
				/* translators: 1: taxonomy, 2: section label */
				sprintf( __( '"%1$s" isn\'t a taxonomy of the %2$s section.', 'causeway-claude-bridge' ), $taxonomy, $section['label'] ),
				400,
				'taxonomy',
				__( 'Use a taxonomy listed for the section in the publishing guide.', 'causeway-claude-bridge' )
			);
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => self::MAX,
			'orderby'    => 'name',
		);
		if ( ! empty( $input['search'] ) ) {
			$args['search'] = (string) $input['search'];
		}
		$terms = get_terms( $args );

		return array(
			'terms' => is_array( $terms ) ? array_values(
				array_map(
					static fn ( WP_Term $term ): array => array(
						'id'     => $term->term_id,
						'slug'   => $term->slug,
						'name'   => html_entity_decode( $term->name, ENT_QUOTES ),
						'parent' => $term->parent,
						'count'  => $term->count,
					),
					$terms
				)
			) : array(),
		);
	}
}
