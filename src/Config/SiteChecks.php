<?php
/**
 * Config checks against the live site (docs/04-configuration.md, "Validation rules").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

/**
 * Checks that what an enabled section refers to exists on the site: its post type, default terms,
 * required taxonomies and ACF field rules.
 *
 * Two modes:
 * - MODE_SAVE: anything missing is an error, so it can't be saved.
 * - MODE_STORED: for a config that was valid when saved; things removed since become warnings
 *   (`config_stale`), and the publishing guide leaves them out.
 *
 * Works on the structure array from SiteStructure, so it needs no WordPress calls itself.
 */
final class SiteChecks {

	public const MODE_SAVE   = 'save';
	public const MODE_STORED = 'stored';

	/**
	 * Runs the checks and adds problems to the result.
	 *
	 * @param array<string, mixed> $config    Config that already passed the schema.
	 * @param array<string, mixed> $structure Site structure.
	 * @param ValidationResult     $result    Collects problems.
	 * @param string               $mode      MODE_SAVE or MODE_STORED.
	 */
	public static function check( array $config, array $structure, ValidationResult $result, string $mode = self::MODE_SAVE ): void {
		$post_types = array_column( $structure['post_types'], null, 'name' );

		foreach ( $config['sections'] as $key => $section ) {
			if ( empty( $section['enabled'] ) ) {
				continue;
			}
			$base      = '/sections/' . $key;
			$post_type = $section['post_type'];

			$missing = static function ( string $path, string $code, string $message ) use ( $result, $mode ): void {
				if ( self::MODE_SAVE === $mode ) {
					$result->add_error( $path, $code, $message );
				} else {
					$result->add_warning( $path, 'config_stale', $message );
				}
			};

			if ( ! isset( $post_types[ $post_type ] ) ) {
				$missing(
					$base . '/post_type',
					'post_type_missing',
					sprintf(
						/* translators: %s: post type name */
						__( 'Post type "%s" isn\'t registered as a public post type on this site.', 'causeway-claude-bridge' ),
						$post_type
					)
				);
				// Nothing else in the section can be checked without its post type.
				continue;
			}

			$type       = $post_types[ $post_type ];
			$taxonomies = $type['taxonomies'];

			if ( 'post' !== $type['capability_type'] ) {
				$result->add_warning(
					$base . '/post_type',
					'post_type_capabilities',
					sprintf(
						/* translators: %s: capability type */
						__( 'This post type uses its own capabilities ("%s"). AI Contributors and AI Publishers may not be able to edit it until they are given matching capabilities.', 'causeway-claude-bridge' ),
						$type['capability_type']
					)
				);
			}

			foreach ( $section['default_terms'] as $taxonomy => $term_ids ) {
				if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
					$missing(
						$base . '/default_terms/' . $taxonomy,
						'taxonomy_not_on_post_type',
						sprintf(
							/* translators: 1: taxonomy, 2: post type */
							__( 'Taxonomy "%1$s" isn\'t used by post type "%2$s".', 'causeway-claude-bridge' ),
							$taxonomy,
							$post_type
						)
					);
					continue;
				}
				$known = array_column( $structure['taxonomies'][ $taxonomy ]['terms'] ?? array(), 'id' );
				foreach ( $term_ids as $index => $term_id ) {
					if ( ! in_array( $term_id, $known, true ) ) {
						$missing(
							$base . '/default_terms/' . $taxonomy . '/' . $index,
							'term_missing',
							sprintf(
								/* translators: 1: term ID, 2: taxonomy */
								__( 'Default term %1$d doesn\'t exist in "%2$s".', 'causeway-claude-bridge' ),
								$term_id,
								$taxonomy
							)
						);
					}
				}
			}

			foreach ( $section['required_taxonomies'] as $index => $taxonomy ) {
				if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
					$missing(
						$base . '/required_taxonomies/' . $index,
						'taxonomy_not_on_post_type',
						sprintf(
							/* translators: 1: taxonomy, 2: post type */
							__( 'Taxonomy "%1$s" isn\'t used by post type "%2$s".', 'causeway-claude-bridge' ),
							$taxonomy,
							$post_type
						)
					);
				}
			}

			if ( array() !== $section['field_rules'] && empty( $structure['acf']['available'] ) ) {
				$missing(
					$base . '/field_rules',
					'acf_inactive',
					__( 'This section has ACF field rules but ACF isn\'t active.', 'causeway-claude-bridge' )
				);
				continue;
			}

			$fields = array_column( $structure['acf']['fields_by_post_type'][ $post_type ] ?? array(), null, 'name' );
			foreach ( $section['field_rules'] as $name => $rule ) {
				$path = $base . '/field_rules/' . $name;
				if ( ! isset( $fields[ $name ] ) ) {
					$missing(
						$path,
						'acf_field_missing',
						sprintf(
							/* translators: 1: field name, 2: post type */
							__( 'ACF field "%1$s" isn\'t in a field group for post type "%2$s".', 'causeway-claude-bridge' ),
							$name,
							$post_type
						)
					);
					continue;
				}
				if ( 'hidden' !== $rule && ! $fields[ $name ]['writable'] ) {
					$result->add_error(
						$path,
						'acf_field_unsupported',
						sprintf(
							/* translators: %s: ACF field type */
							__( 'Claude can\'t fill "%s" fields, so this field must be hidden.', 'causeway-claude-bridge' ),
							$fields[ $name ]['type']
						)
					);
				}
			}

			foreach ( array_keys( $section['extra_fields'] ) as $meta_key ) {
				if ( isset( $fields[ $meta_key ] ) ) {
					$result->add_error(
						$base . '/extra_fields/' . $meta_key,
						'extra_field_is_acf',
						__( 'An ACF field already uses this name. Set its rule under ACF fields instead.', 'causeway-claude-bridge' )
					);
				}
			}
		}
	}
}
