<?php
/**
 * Config presets (docs/04-configuration.md, "Presets").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

/**
 * Partial configs in `presets/*.json` that an admin can apply from the Advanced tab.
 *
 * Applying a preset never saves by itself: preview() returns the merged config, a diff against the
 * current one, and what couldn't be mapped. Only sections whose post type exists are taken, and terms
 * written as `slug:<slug>` are resolved to IDs on this site; missing slugs are reported and dropped.
 */
final class Presets {

	private const SLUG_PREFIX = 'slug:';

	/**
	 * Folder holding the preset files.
	 *
	 * @var string
	 */
	private string $directory;

	/**
	 * Sets up the preset reader.
	 *
	 * @param string|null $directory Folder with the preset files; defaults to the plugin's `presets/`.
	 */
	public function __construct( ?string $directory = null ) {
		$this->directory = $directory ?? dirname( __DIR__, 2 ) . '/presets';
	}

	/**
	 * Available presets.
	 *
	 * @return list<array{name: string, label: string, description: string}>
	 */
	public function all(): array {
		$list  = array();
		$files = glob( $this->directory . '/*.json' );
		foreach ( false === $files ? array() : $files as $file ) {
			$preset = $this->read( basename( $file, '.json' ) );
			if ( null !== $preset ) {
				$list[] = array(
					'name'        => basename( $file, '.json' ),
					'label'       => (string) ( $preset['label'] ?? basename( $file, '.json' ) ),
					'description' => (string) ( $preset['description'] ?? '' ),
				);
			}
		}
		return $list;
	}

	/**
	 * Reads a preset file.
	 *
	 * @param string $name Preset name (file name without `.json`).
	 * @return array<string, mixed>|null
	 */
	public function read( string $name ): ?array {
		if ( ! preg_match( '/^[a-z0-9-]+$/', $name ) ) {
			return null;
		}
		$file = $this->directory . '/' . $name . '.json';
		if ( ! is_readable( $file ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file bundled with the plugin.
		return is_array( $data ) && isset( $data['config'] ) && is_array( $data['config'] ) ? $data : null;
	}

	/**
	 * What applying a preset to the current config would do.
	 *
	 * @param string               $name      Preset name.
	 * @param array<string, mixed> $current   Current config.
	 * @param array<string, mixed> $structure Site structure.
	 * @return array{config: array<string, mixed>, changes: list<array{path: string, before: mixed, after: mixed}>, skipped_sections: list<array{key: string, post_type: string}>, missing_terms: list<array{section: string, taxonomy: string, slug: string}>}|null Null when the preset doesn't exist.
	 */
	public function preview( string $name, array $current, array $structure ): ?array {
		$preset = $this->read( $name );
		if ( null === $preset ) {
			return null;
		}

		$partial    = $preset['config'];
		$config     = $current;
		$post_types = array_column( $structure['post_types'], 'name' );
		$skipped    = array();
		$missing    = array();

		foreach ( $partial['sections'] ?? array() as $key => $section ) {
			$key = (string) $key;
			if ( ! in_array( $section['post_type'] ?? '', $post_types, true ) ) {
				$skipped[] = array(
					'key'       => $key,
					'post_type' => (string) ( $section['post_type'] ?? '' ),
				);
				continue;
			}

			$base = $config['sections'][ $key ] ?? DefaultConfig::section( $section['post_type'], (string) ( $section['label'] ?? $key ) );

			foreach ( $section['default_terms'] ?? array() as $taxonomy => $terms ) {
				$section['default_terms'][ $taxonomy ] = $this->resolve_terms( (array) $terms, (string) $taxonomy, $structure, $key, $missing );
				if ( array() === $section['default_terms'][ $taxonomy ] ) {
					unset( $section['default_terms'][ $taxonomy ] );
				}
			}

			$config['sections'][ $key ] = self::merge( $base, $section );
		}

		foreach ( $partial as $block => $values ) {
			if ( 'sections' === $block || ! is_array( $values ) || ! isset( $config[ $block ] ) || ! is_array( $config[ $block ] ) ) {
				continue;
			}
			$config[ $block ] = self::merge( $config[ $block ], $values );
		}

		return array(
			'config'           => $config,
			'changes'          => ConfigDiff::diff( $current, $config ),
			'skipped_sections' => $skipped,
			'missing_terms'    => $missing,
		);
	}

	/**
	 * Turns `slug:<slug>` entries into term IDs; IDs are kept if they exist.
	 *
	 * @param array<int, mixed>                                            $terms     Terms from the preset.
	 * @param string                                                       $taxonomy  Taxonomy.
	 * @param array<string, mixed>                                         $structure Site structure.
	 * @param string                                                       $section   Section key, for reporting.
	 * @param list<array{section: string, taxonomy: string, slug: string}> $missing   Collects unresolved slugs.
	 * @return list<int>
	 */
	private function resolve_terms( array $terms, string $taxonomy, array $structure, string $section, array &$missing ): array {
		$known = $structure['taxonomies'][ $taxonomy ]['terms'] ?? array();
		$ids   = array();
		foreach ( $terms as $term ) {
			if ( is_string( $term ) && str_starts_with( $term, self::SLUG_PREFIX ) ) {
				$slug  = substr( $term, strlen( self::SLUG_PREFIX ) );
				$match = array_values( array_filter( $known, static fn ( array $t ): bool => $t['slug'] === $slug ) );
				if ( array() === $match ) {
					$missing[] = array(
						'section'  => $section,
						'taxonomy' => $taxonomy,
						'slug'     => $slug,
					);
					continue;
				}
				$ids[] = (int) $match[0]['id'];
			} elseif ( is_int( $term ) && in_array( $term, array_column( $known, 'id' ), true ) ) {
				$ids[] = $term;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Recursive merge where the preset wins; lists are replaced, not appended.
	 *
	 * @param array<string, mixed> $base  Base values.
	 * @param array<string, mixed> $patch Preset values.
	 * @return array<string, mixed>
	 */
	private static function merge( array $base, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) && ! array_is_list( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && ( array() === $base[ $key ] || ! array_is_list( $base[ $key ] ) ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
