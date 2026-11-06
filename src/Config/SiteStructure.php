<?php
/**
 * Live site discovery (docs/04-configuration.md, "Site discovery").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

/**
 * Reads post types, taxonomies, terms, ACF fields, Elementor single templates and the SEO plugin
 * from WordPress. The result is cached for 5 minutes and cleared when terms or ACF field groups change.
 *
 * Shape (all keys always present):
 * - post_types: list of { name, label, supports, show_in_rest, capability_type, taxonomies }
 * - taxonomies: map of name => { label, hierarchical, terms: list of { id, slug, name, parent } }
 * - acf: { available, fields_by_post_type: map of post type => list of field }
 * - elementor: { active, single_templates: list of { id, title, conditions } }
 * - seo_plugin: yoast | rankmath | seopress | none
 */
final class SiteStructure {

	public const TRANSIENT = 'ccb_site_structure';
	public const TTL       = 5 * MINUTE_IN_SECONDS;

	/**
	 * Post types that are never sections.
	 */
	public const EXCLUDED_POST_TYPES = array( 'attachment', 'page', 'elementor_library', 'e-landing-page' );

	/**
	 * ACF field types the Bridge can write (docs/04). Anything else is hidden from Claude.
	 */
	public const WRITABLE_ACF_TYPES = array( 'text', 'textarea', 'number', 'email', 'url', 'select', 'radio', 'checkbox', 'true_false', 'date_picker', 'file', 'image', 'page_link', 'post_object', 'wysiwyg' );

	public const MAX_TERMS = 500;

	/**
	 * Hooks that clear the cache.
	 */
	public static function register_hooks(): void {
		// Not registered_post_type/registered_taxonomy: those fire on every request and would defeat the cache.
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( self::class, 'clear_cache' ) );
		}
		add_action(
			'save_post_acf-field-group',
			array( self::class, 'clear_cache' )
		);
		add_action( 'acf/update_field_group', array( self::class, 'clear_cache' ) );
		add_action( 'acf/delete_field_group', array( self::class, 'clear_cache' ) );
		add_action( 'activated_plugin', array( self::class, 'clear_cache' ) );
		add_action( 'deactivated_plugin', array( self::class, 'clear_cache' ) );
	}

	/**
	 * Deletes the cached structure.
	 */
	public static function clear_cache(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * The site structure, from cache when fresh.
	 *
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['post_types'] ) ) {
			return $cached;
		}
		$structure = $this->discover();
		set_transient( self::TRANSIENT, $structure, self::TTL );
		return $structure;
	}

	/**
	 * Reads everything from WordPress, bypassing the cache.
	 *
	 * @return array<string, mixed>
	 */
	public function discover(): array {
		$post_types = $this->post_types();
		$taxonomies = array();
		foreach ( $post_types as $post_type ) {
			foreach ( $post_type['taxonomies'] as $taxonomy ) {
				$taxonomies[ $taxonomy ] ??= $this->taxonomy( $taxonomy );
			}
		}

		return array(
			'post_types' => $post_types,
			'taxonomies' => $taxonomies,
			'acf'        => $this->acf( array_column( $post_types, 'name' ) ),
			'elementor'  => $this->elementor(),
			'seo_plugin' => self::detect_seo_plugin(),
		);
	}

	/**
	 * Public post types that can be sections.
	 *
	 * @return list<array{name: string, label: string, supports: list<string>, show_in_rest: bool, capability_type: string, taxonomies: list<string>}>
	 */
	private function post_types(): array {
		$result = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $object ) {
			if ( in_array( $object->name, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}
			$taxonomies = array_values(
				array_filter(
					get_object_taxonomies( $object->name ),
					static function ( string $taxonomy ): bool {
						$object = get_taxonomy( $taxonomy );
						return 'post_format' !== $taxonomy && false !== $object && $object->show_ui;
					}
				)
			);
			// register_post_type() accepts array( 'book', 'books' ) here even though it's documented as a string.
			$capability_types = (array) $object->capability_type;
			$capability       = (string) reset( $capability_types );

			$result[] = array(
				'name'            => $object->name,
				'label'           => (string) $object->label,
				'supports'        => array_keys( array_filter( (array) get_all_post_type_supports( $object->name ) ) ),
				'show_in_rest'    => (bool) $object->show_in_rest,
				'capability_type' => $capability,
				'taxonomies'      => $taxonomies,
			);
		}
		usort( $result, static fn ( array $a, array $b ): int => strcmp( $a['name'], $b['name'] ) );
		return $result;
	}

	/**
	 * One taxonomy and its terms.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array{label: string, hierarchical: bool, terms: list<array{id: int, slug: string, name: string, parent: int}>}
	 */
	private function taxonomy( string $taxonomy ): array {
		$object = get_taxonomy( $taxonomy );
		$terms  = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => self::MAX_TERMS,
				'orderby'    => 'name',
			)
		);

		$list = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$list[] = array(
					'id'     => $term->term_id,
					'slug'   => $term->slug,
					'name'   => html_entity_decode( $term->name, ENT_QUOTES ),
					'parent' => $term->parent,
				);
			}
		}

		return array(
			'label'        => $object ? (string) $object->labels->singular_name : $taxonomy,
			'hierarchical' => $object && $object->hierarchical,
			'terms'        => $list,
		);
	}

	/**
	 * ACF fields per post type, including local (PHP-registered) groups.
	 *
	 * @param string[] $post_types Post type names.
	 * @return array{available: bool, fields_by_post_type: array<string, list<array<string, mixed>>>}
	 */
	private function acf( array $post_types ): array {
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
			return array(
				'available'           => false,
				'fields_by_post_type' => array(),
			);
		}

		$by_type = array();
		foreach ( $post_types as $post_type ) {
			$fields = array();
			foreach ( acf_get_field_groups( array( 'post_type' => $post_type ) ) as $group ) {
				foreach ( (array) acf_get_fields( $group ) as $field ) {
					if ( ! is_array( $field ) || empty( $field['name'] ) ) {
						continue;
					}
					$fields[] = self::describe_acf_field( $field, (string) ( $group['title'] ?? '' ) );
				}
			}
			if ( array() !== $fields ) {
				$by_type[ $post_type ] = $fields;
			}
		}

		return array(
			'available'           => true,
			'fields_by_post_type' => $by_type,
		);
	}

	/**
	 * The parts of an ACF field the Bridge uses.
	 *
	 * @param array<string, mixed> $field ACF field.
	 * @param string               $group Group title.
	 * @return array<string, mixed>
	 */
	private static function describe_acf_field( array $field, string $group ): array {
		$type        = (string) ( $field['type'] ?? '' );
		$description = array(
			'key'          => (string) ( $field['key'] ?? '' ),
			'name'         => (string) $field['name'],
			'label'        => (string) ( $field['label'] ?? $field['name'] ),
			'type'         => $type,
			'required'     => ! empty( $field['required'] ),
			'group'        => $group,
			'writable'     => in_array( $type, self::WRITABLE_ACF_TYPES, true ),
			'instructions' => (string) ( $field['instructions'] ?? '' ),
		);
		if ( isset( $field['choices'] ) && is_array( $field['choices'] ) && array() !== $field['choices'] ) {
			$description['choices'] = array_map( 'strval', $field['choices'] );
		}
		if ( in_array( $type, array( 'checkbox', 'select' ), true ) ) {
			$description['multiple'] = 'checkbox' === $type || ! empty( $field['multiple'] );
		}
		return $description;
	}

	/**
	 * Elementor Theme Builder single templates and their display conditions (read-only).
	 *
	 * TO CONFIRM against the site's Elementor Pro version: template type in `_elementor_template_type`
	 * (`single`, `single-post`) and conditions in `_elementor_conditions` (e.g. `include/singular/post`).
	 *
	 * @return array{active: bool, single_templates: list<array{id: int, title: string, conditions: list<string>}>}
	 */
	private function elementor(): array {
		$active = defined( 'ELEMENTOR_VERSION' ) || post_type_exists( 'elementor_library' );
		if ( ! post_type_exists( 'elementor_library' ) ) {
			return array(
				'active'           => $active,
				'single_templates' => array(),
			);
		}

		$templates = get_posts(
			array(
				'post_type'      => 'elementor_library',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-side discovery, cached.
				'meta_query'     => array(
					array(
						'key'     => '_elementor_template_type',
						'value'   => array( 'single', 'single-post' ),
						'compare' => 'IN',
					),
				),
			)
		);

		$list = array();
		foreach ( $templates as $template ) {
			$conditions = get_post_meta( $template->ID, '_elementor_conditions', true );
			$list[]     = array(
				'id'         => $template->ID,
				'title'      => $template->post_title,
				'conditions' => is_array( $conditions ) ? array_values( array_map( 'strval', $conditions ) ) : array(),
			);
		}

		return array(
			'active'           => $active,
			'single_templates' => $list,
		);
	}

	/**
	 * Which supported SEO plugin is active.
	 */
	public static function detect_seo_plugin(): string {
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		return 'none';
	}

	/**
	 * Single templates whose conditions cover a post type, from a structure array.
	 *
	 * Matches `include/singular/{post_type}`, `include/singular` and `include/general`, unless an
	 * `exclude/singular/{post_type}` condition is present.
	 *
	 * @param array<string, mixed> $structure Structure from get().
	 * @param string               $post_type Post type.
	 * @return list<array{id: int, title: string, conditions: list<string>}>
	 */
	public static function templates_for( array $structure, string $post_type ): array {
		$matches = array();
		foreach ( $structure['elementor']['single_templates'] ?? array() as $template ) {
			$conditions = $template['conditions'];
			if ( in_array( 'exclude/singular/' . $post_type, $conditions, true ) ) {
				continue;
			}
			foreach ( $conditions as $condition ) {
				if ( in_array( $condition, array( 'include/general', 'include/singular', 'include/singular/' . $post_type ), true )
					|| str_starts_with( $condition, 'include/singular/' . $post_type . '/' ) ) {
					$matches[] = $template;
					break;
				}
			}
		}
		return $matches;
	}
}
