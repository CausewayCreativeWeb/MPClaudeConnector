<?php
/**
 * Terms for a post (docs/05 "Terms"; non-negotiable 7).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use WP_Error;
use WP_Term;

/**
 * Resolves term IDs or slugs to existing terms in the section's taxonomies. Never creates terms:
 * unknown terms are an error, and only integer IDs are ever passed to wp_set_object_terms().
 */
final class TermResolver {

	/**
	 * The section's taxonomies from the publishing guide.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $taxonomies;

	/**
	 * Sets up the resolver.
	 *
	 * @param array<string, mixed> $guide_section Section entry from the publishing guide.
	 */
	public function __construct( array $guide_section ) {
		$this->taxonomies = $guide_section['taxonomies'];
	}

	/**
	 * Resolves the given terms. Taxonomies left out aren't touched; an empty list gets the defaults.
	 *
	 * @param array<string, mixed> $input Taxonomy => list of term IDs or slugs.
	 * @return array<string, list<int>>|WP_Error
	 */
	public function resolve( array $input ): array|WP_Error {
		$resolved = array();
		foreach ( $input as $taxonomy => $terms ) {
			$taxonomy = (string) $taxonomy;
			if ( ! isset( $this->taxonomies[ $taxonomy ] ) ) {
				return AbilityError::make(
					'ccb_term_unknown',
					/* translators: %s: taxonomy */
					sprintf( __( '"%s" isn\'t a taxonomy of this section.', 'causeway-claude-bridge' ), $taxonomy ),
					400,
					'terms.' . $taxonomy,
					__( 'Use the taxonomies listed in the publishing guide.', 'causeway-claude-bridge' )
				);
			}
			if ( ! is_array( $terms ) ) {
				$terms = array( $terms );
			}

			$ids = array();
			foreach ( $terms as $term ) {
				$found = null;
				if ( is_int( $term ) || ( is_string( $term ) && ctype_digit( $term ) ) ) {
					$found = get_term( (int) $term, $taxonomy );
				} elseif ( is_string( $term ) ) {
					$found = get_term_by( 'slug', sanitize_title( $term ), $taxonomy );
					if ( ! $found instanceof WP_Term ) {
						$found = get_term_by( 'name', $term, $taxonomy );
					}
				}
				if ( ! $found instanceof WP_Term ) {
					return AbilityError::make(
						'ccb_term_unknown',
						/* translators: 1: term as sent, 2: taxonomy label */
						sprintf( __( '"%1$s" isn\'t an existing %2$s. New terms are never created.', 'causeway-claude-bridge' ), is_scalar( $term ) ? (string) $term : '?', $this->taxonomies[ $taxonomy ]['label'] ),
						400,
						'terms.' . $taxonomy,
						__( 'Choose from the terms listed in the publishing guide, or call list-terms.', 'causeway-claude-bridge' )
					);
				}
				$ids[] = $found->term_id;
			}

			$ids                   = array_values( array_unique( $ids ) );
			$resolved[ $taxonomy ] = array() === $ids ? $this->defaults( $taxonomy ) : $ids;
		}
		return $resolved;
	}

	/**
	 * Default terms for every taxonomy the input leaves out (used when creating a post).
	 *
	 * @param array<string, list<int>> $resolved Already resolved terms.
	 * @return array<string, list<int>>
	 */
	public function with_defaults( array $resolved ): array {
		foreach ( array_keys( $this->taxonomies ) as $taxonomy ) {
			if ( ! isset( $resolved[ $taxonomy ] ) ) {
				$defaults = $this->defaults( (string) $taxonomy );
				if ( array() !== $defaults ) {
					$resolved[ (string) $taxonomy ] = $defaults;
				}
			}
		}
		return $resolved;
	}

	/**
	 * Writes terms, replacing what was there for each given taxonomy.
	 *
	 * @param int                      $post_id Post.
	 * @param array<string, list<int>> $terms   Resolved terms.
	 */
	public static function write( int $post_id, array $terms ): void {
		foreach ( $terms as $taxonomy => $ids ) {
			wp_set_object_terms( $post_id, array_map( 'intval', $ids ), $taxonomy, false );
		}
	}

	/**
	 * Default term IDs for a taxonomy (only ones that still exist).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return list<int>
	 */
	private function defaults( string $taxonomy ): array {
		return array_values( array_map( 'intval', $this->taxonomies[ $taxonomy ]['default'] ?? array() ) );
	}
}
