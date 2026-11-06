<?php
/**
 * Publishing guide (docs/04-configuration.md, "Publishing guide"; docs/03 `ccb/get-publishing-guide`).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

/**
 * Merges the config with the live site structure into what Claude reads before writing:
 * structured rules plus a short plain-English guide per section.
 *
 * Only enabled sections whose post type exists are included. Terms and fields that no longer exist
 * are left out. Works on arrays only, so the admin UI can preview an unsaved config with it.
 */
final class PublishingGuide {

	/**
	 * Builds the guide.
	 *
	 * @param array<string, mixed> $config      Config.
	 * @param array<string, mixed> $structure   Site structure.
	 * @param string|null          $section_key Only this section; null for all.
	 * @return array{config_version: int, general: array<string, mixed>, sections: list<array<string, mixed>>}
	 */
	public static function build( array $config, array $structure, ?string $section_key = null ): array {
		$post_types = array_column( $structure['post_types'], null, 'name' );
		$sections   = array();

		foreach ( $config['sections'] as $key => $section ) {
			$key = (string) $key;
			if ( empty( $section['enabled'] ) || ( null !== $section_key && $key !== $section_key ) ) {
				continue;
			}
			if ( ! isset( $post_types[ $section['post_type'] ] ) ) {
				continue;
			}
			$sections[] = self::section( $key, $section, $post_types[ $section['post_type'] ], $structure, $config );
		}

		return array(
			'config_version' => (int) ( $config['version'] ?? 0 ),
			'general'        => self::general( $config ),
			'sections'       => $sections,
		);
	}

	/**
	 * Site-wide writing, image and upload rules.
	 *
	 * @param array<string, mixed> $config Config.
	 * @return array<string, mixed>
	 */
	private static function general( array $config ): array {
		$writing = $config['writing'];
		$media   = $config['media'];

		return array(
			'language'        => $writing['language'],
			'headings'        => $writing['headings'],
			'allowed_html'    => $writing['allowed_html'],
			'house_style'     => $writing['house_style'],
			'words_to_avoid'  => $writing['words_to_avoid'],
			'sign_off'        => $writing['sign_off'],
			'seo'             => array(
				'title_max'       => $writing['seo']['title_max'],
				'description_max' => $writing['seo']['description_max'],
			),
			'images'          => array(
				'types'              => $media['image_types'],
				'content_min_width'  => 800,
				'alt_text_required'  => true,
				'alt_text_max_chars' => 125,
			),
			'uploads'         => array(
				'max_upload_mb'        => $media['max_upload_mb'],
				'pdf_max_mb'           => $media['pdf_max_mb'],
				'pdfs_use_upload_link' => true,
			),
			'support_contact' => $config['support'],
		);
	}

	/**
	 * One section's rules and guide.
	 *
	 * @param string               $key       Section key.
	 * @param array<string, mixed> $section   Section config.
	 * @param array<string, mixed> $post_type Post type from the structure.
	 * @param array<string, mixed> $structure Site structure.
	 * @param array<string, mixed> $config    Full config.
	 * @return array<string, mixed>
	 */
	private static function section( string $key, array $section, array $post_type, array $structure, array $config ): array {
		$taxonomies = array();
		foreach ( $post_type['taxonomies'] as $taxonomy ) {
			$info  = $structure['taxonomies'][ $taxonomy ] ?? null;
			$terms = $info['terms'] ?? array();
			$ids   = array_column( $terms, 'id' );

			$taxonomies[ $taxonomy ] = array(
				'label'    => $info['label'] ?? $taxonomy,
				'required' => in_array( $taxonomy, $section['required_taxonomies'], true ),
				'multiple' => true,
				'default'  => array_values( array_intersect( $section['default_terms'][ $taxonomy ] ?? array(), $ids ) ),
				'terms'    => $terms,
			);
		}

		$fields = self::fields( $section, $structure['acf']['fields_by_post_type'][ $section['post_type'] ] ?? array() );

		$built = array(
			'key'             => $key,
			'label'           => $section['label'],
			'post_type'       => $section['post_type'],
			'post_type_label' => $post_type['label'],
			'guide'           => '',
			'taxonomies'      => $taxonomies,
			'fields'          => $fields,
			'featured_image'  => $section['featured_image'],
			'limits'          => $section['limits'],
		);

		$built['guide'] = self::markdown( $built, $section, $config );
		return $built;
	}

	/**
	 * ACF fields (minus hidden and unwritable ones) plus config-defined extra fields.
	 *
	 * @param array<string, mixed>       $section    Section config.
	 * @param list<array<string, mixed>> $acf_fields ACF fields for the post type.
	 * @return list<array<string, mixed>>
	 */
	private static function fields( array $section, array $acf_fields ): array {
		$fields = array();

		foreach ( $acf_fields as $field ) {
			$rule = $section['field_rules'][ $field['name'] ] ?? ( $field['required'] ? 'required' : 'optional' );
			if ( 'hidden' === $rule || ! $field['writable'] ) {
				continue;
			}
			$entry = array(
				'name'   => $field['name'],
				'label'  => $field['label'],
				'type'   => $field['type'],
				'rule'   => $rule,
				'source' => 'acf',
			);
			if ( isset( $field['choices'] ) ) {
				$entry['choices'] = array_map( 'strval', array_keys( $field['choices'] ) );
			}
			if ( '' !== $field['instructions'] ) {
				$entry['instructions'] = $field['instructions'];
			}
			$fields[] = $entry;
		}

		foreach ( $section['extra_fields'] as $meta_key => $extra ) {
			$entry = array(
				'name'   => (string) $meta_key,
				'label'  => $extra['label'],
				'type'   => $extra['type'],
				'rule'   => $extra['required'] ? 'required' : 'optional',
				'source' => 'meta',
			);
			if ( isset( $extra['choices'] ) ) {
				$entry['choices'] = $extra['choices'];
			}
			if ( isset( $extra['instructions'] ) && '' !== $extra['instructions'] ) {
				$entry['instructions'] = $extra['instructions'];
			}
			$fields[] = $entry;
		}

		return $fields;
	}

	/**
	 * Plain-English markdown Claude follows for the section.
	 *
	 * @param array<string, mixed> $built   Section as built so far.
	 * @param array<string, mixed> $section Section config.
	 * @param array<string, mixed> $config  Full config.
	 */
	private static function markdown( array $built, array $section, array $config ): string {
		$lines = array();

		// Taxonomies.
		$required = array();
		foreach ( $built['taxonomies'] as $taxonomy => $info ) {
			if ( $info['required'] ) {
				$required[] = sprintf( '**%s** (%s)', $info['label'], $taxonomy );
			}
		}
		if ( array() !== $required ) {
			$lines[] = sprintf(
				/* translators: %s: list of taxonomies */
				__( 'Choose at least one %s from the lists provided. Never invent new ones.', 'causeway-claude-bridge' ),
				self::join( $required )
			);
		}
		foreach ( $built['taxonomies'] as $taxonomy => $info ) {
			if ( ! $info['required'] && array() !== $info['terms'] ) {
				$lines[] = sprintf(
					/* translators: 1: taxonomy label, 2: taxonomy name */
					__( 'You may choose **%1$s** (%2$s) from the list provided. Never invent new ones.', 'causeway-claude-bridge' ),
					$info['label'],
					$taxonomy
				);
			}
			if ( array() !== $info['default'] ) {
				$names   = array_column( array_filter( $info['terms'], static fn ( array $t ): bool => in_array( $t['id'], $info['default'], true ) ), 'name' );
				$lines[] = sprintf(
					/* translators: 1: taxonomy label, 2: default term names */
					__( 'If no **%1$s** is chosen, it defaults to %2$s.', 'causeway-claude-bridge' ),
					$info['label'],
					self::join( $names )
				);
			}
		}

		// Fields.
		foreach ( $built['fields'] as $field ) {
			$lines[] = self::field_line( $field );
		}

		// Featured image.
		$image = $section['featured_image'];
		if ( $image['required'] ) {
			$parts = array();
			if ( 'any' !== $image['ratio'] ) {
				$parts[] = self::ratio_text( $image['ratio'] );
			}
			if ( $image['min_width'] > 0 ) {
				/* translators: %d: minimum width in pixels */
				$parts[] = sprintf( __( 'at least %dpx wide', 'causeway-claude-bridge' ), $image['min_width'] );
			}
			$parts[] = __( 'with alt text', 'causeway-claude-bridge' );
			/* translators: %s: image requirements */
			$lines[] = sprintf( __( 'A featured image is required: %s.', 'causeway-claude-bridge' ), implode( ', ', $parts ) );
		}

		// Limits.
		$limits = $section['limits'];
		if ( $limits['min_words'] > 0 && $limits['max_words'] > 0 ) {
			/* translators: 1: minimum words, 2: maximum words */
			$lines[] = sprintf( __( 'Length: %1$s to %2$s words.', 'causeway-claude-bridge' ), self::number( $limits['min_words'] ), self::number( $limits['max_words'] ) );
		} elseif ( $limits['max_words'] > 0 ) {
			/* translators: %s: maximum words */
			$lines[] = sprintf( __( 'Length: at most %s words.', 'causeway-claude-bridge' ), self::number( $limits['max_words'] ) );
		} elseif ( $limits['min_words'] > 0 ) {
			/* translators: %s: minimum words */
			$lines[] = sprintf( __( 'Length: at least %s words.', 'causeway-claude-bridge' ), self::number( $limits['min_words'] ) );
		}
		if ( $limits['excerpt_max_chars'] > 0 ) {
			/* translators: %s: maximum characters */
			$lines[] = sprintf( __( 'Excerpt: up to %s characters.', 'causeway-claude-bridge' ), self::number( $limits['excerpt_max_chars'] ) );
		}
		if ( $limits['max_images'] > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: maximum number of images */
				_n( 'At most %s image in the post.', 'At most %s images in the post.', $limits['max_images'], 'causeway-claude-bridge' ),
				self::number( $limits['max_images'] )
			);
		}

		// Writing rules.
		$headings = array_map( 'strtoupper', $config['writing']['headings'] );
		$lines[]  = sprintf(
			/* translators: 1: allowed headings, 2: language name */
			__( 'Headings: %1$s only. %2$s.', 'causeway-claude-bridge' ),
			self::join( $headings ),
			self::language_name( $config['writing']['language'] )
		);

		if ( '' !== trim( $section['notes'] ) ) {
			/* translators: %s: notes from the site administrator */
			$lines[] = sprintf( __( 'Extra notes: %s', 'causeway-claude-bridge' ), trim( $section['notes'] ) );
		}

		$title = sprintf( '## %s (%s: %s)', $built['label'], __( 'post type', 'causeway-claude-bridge' ), $built['post_type'] );
		return $title . "\n" . implode( "\n", array_map( static fn ( string $line ): string => '- ' . $line, $lines ) ) . "\n";
	}

	/**
	 * Guide line for one field.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private static function field_line( array $field ): string {
		$name = sprintf( '**%s**', $field['label'] );
		$type = self::type_text( $field );

		if ( 'uk_postcode_to_latlng' === $field['type'] ) {
			$line = 'required' === $field['rule']
				/* translators: 1: field label, 2: field name */
				? sprintf( __( '%1$s is required: send the UK postcode as `{ "postcode": "…" }` in field `%2$s`. The site looks up the map position.', 'causeway-claude-bridge' ), $name, $field['name'] )
				/* translators: 1: field label, 2: field name */
				: sprintf( __( '%1$s is optional: send the UK postcode as `{ "postcode": "…" }` in field `%2$s`.', 'causeway-claude-bridge' ), $name, $field['name'] );
		} elseif ( 'file' === $field['type'] ) {
			$line = sprintf(
				/* translators: 1: field label, 2: field name, 3: "required" or "optional" */
				__( '%1$s (field `%2$s`) is %3$s. Files for it always go through an upload link (`ccb/get-upload-link`, purpose `field_file`).', 'causeway-claude-bridge' ),
				$name,
				$field['name'],
				'required' === $field['rule'] ? __( 'required', 'causeway-claude-bridge' ) : __( 'optional', 'causeway-claude-bridge' )
			);
		} elseif ( 'only_if_given' === $field['rule'] ) {
			/* translators: 1: field label, 2: field name, 3: field type description */
			$line = sprintf( __( 'Only set %1$s (field `%2$s`, %3$s) if the user gives one.', 'causeway-claude-bridge' ), $name, $field['name'], $type );
		} elseif ( 'required' === $field['rule'] ) {
			/* translators: 1: field label, 2: field name, 3: field type description */
			$line = sprintf( __( '%1$s is required (field `%2$s`, %3$s).', 'causeway-claude-bridge' ), $name, $field['name'], $type );
		} else {
			/* translators: 1: field label, 2: field name, 3: field type description */
			$line = sprintf( __( '%1$s is optional (field `%2$s`, %3$s).', 'causeway-claude-bridge' ), $name, $field['name'], $type );
		}

		if ( isset( $field['choices'] ) && array() !== $field['choices'] ) {
			/* translators: %s: allowed values */
			$line .= ' ' . sprintf( __( 'Allowed values: %s.', 'causeway-claude-bridge' ), implode( ', ', array_map( static fn ( string $c ): string => '`' . $c . '`', $field['choices'] ) ) );
		}
		if ( isset( $field['instructions'] ) ) {
			$line .= ' ' . rtrim( $field['instructions'], '.' ) . '.';
		}
		return $line;
	}

	/**
	 * Short description of a field's expected value.
	 *
	 * @param array<string, mixed> $field Field.
	 */
	private static function type_text( array $field ): string {
		return match ( $field['type'] ) {
			'text'        => __( 'plain text', 'causeway-claude-bridge' ),
			'textarea'    => __( 'plain text, line breaks kept', 'causeway-claude-bridge' ),
			'wysiwyg'     => __( 'HTML, same rules as the post body', 'causeway-claude-bridge' ),
			'number'      => __( 'a number', 'causeway-claude-bridge' ),
			'email'       => __( 'an email address', 'causeway-claude-bridge' ),
			'url'         => __( 'a full URL', 'causeway-claude-bridge' ),
			'select', 'radio' => __( 'one of the allowed values', 'causeway-claude-bridge' ),
			'checkbox'    => __( 'any of the allowed values', 'causeway-claude-bridge' ),
			'true_false'  => __( 'true or false', 'causeway-claude-bridge' ),
			'date_picker', 'date' => __( 'a date as YYYY-MM-DD', 'causeway-claude-bridge' ),
			'image'       => __( 'an uploaded image\'s attachment ID', 'causeway-claude-bridge' ),
			'page_link', 'post_object' => __( 'a post ID or a URL on this site', 'causeway-claude-bridge' ),
			default       => $field['type'],
		};
	}

	/**
	 * Words for an image ratio.
	 *
	 * @param string $ratio Ratio from config.
	 */
	private static function ratio_text( string $ratio ): string {
		return match ( $ratio ) {
			'16:9'  => __( 'landscape 16:9', 'causeway-claude-bridge' ),
			'4:3'   => __( 'landscape 4:3', 'causeway-claude-bridge' ),
			'1:1'   => __( 'square', 'causeway-claude-bridge' ),
			default => $ratio,
		};
	}

	/**
	 * Name of the writing language.
	 *
	 * @param string $language Locale such as en-GB.
	 */
	private static function language_name( string $language ): string {
		return match ( $language ) {
			'en-GB' => __( 'UK English', 'causeway-claude-bridge' ),
			'en-US' => __( 'US English', 'causeway-claude-bridge' ),
			'en'    => __( 'English', 'causeway-claude-bridge' ),
			/* translators: %s: language code such as cy-GB */
			default => sprintf( __( 'Write in %s', 'causeway-claude-bridge' ), $language ),
		};
	}

	/**
	 * "a", "a and b", "a, b and c".
	 *
	 * @param string[] $items Items.
	 */
	private static function join( array $items ): string {
		$items = array_values( $items );
		if ( count( $items ) <= 1 ) {
			return (string) ( $items[0] ?? '' );
		}
		$last = array_pop( $items );
		/* translators: 1: comma-separated items, 2: last item */
		return sprintf( __( '%1$s and %2$s', 'causeway-claude-bridge' ), implode( ', ', $items ), $last );
	}

	/**
	 * Number with thousands separator.
	 *
	 * @param int $value Number.
	 */
	private static function number( int $value ): string {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( $value ) : number_format( $value );
	}
}
