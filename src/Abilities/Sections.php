<?php
/**
 * Resolves sections for abilities (docs/03 "Section").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Config\SiteStructure;
use WP_Error;
use WP_Post;

/**
 * The sections Claude can use right now: enabled in the config, with a post type that still exists.
 */
final class Sections {

	/**
	 * Live config.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Site structure.
	 *
	 * @var array<string, mixed>
	 */
	private array $structure;

	/**
	 * Loads the config and site structure.
	 *
	 * @param ConfigRepository|null $config    Config repository.
	 * @param SiteStructure|null    $structure Site discovery.
	 */
	public function __construct( ?ConfigRepository $config = null, ?SiteStructure $structure = null ) {
		$this->config    = ( $config ?? new ConfigRepository() )->get();
		$this->structure = ( $structure ?? new SiteStructure() )->get();
	}

	/**
	 * The live config.
	 *
	 * @return array<string, mixed>
	 */
	public function config(): array {
		return $this->config;
	}

	/**
	 * The site structure.
	 *
	 * @return array<string, mixed>
	 */
	public function structure(): array {
		return $this->structure;
	}

	/**
	 * Usable sections, keyed by section key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$post_types = array_column( $this->structure['post_types'], 'name' );
		$sections   = array();
		foreach ( $this->config['sections'] as $key => $section ) {
			if ( ! empty( $section['enabled'] ) && in_array( $section['post_type'], $post_types, true ) ) {
				$sections[ (string) $key ] = $section;
			}
		}
		return $sections;
	}

	/**
	 * One section by key.
	 *
	 * @param string $key Section key.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get( string $key ): array|WP_Error {
		$all = $this->all();
		if ( isset( $all[ $key ] ) ) {
			return $all[ $key ];
		}
		return AbilityError::make(
			'ccb_section_unknown',
			/* translators: %s: section key */
			sprintf( __( 'There is no "%s" section you can post to.', 'causeway-claude-bridge' ), $key ),
			400,
			'section',
			/* translators: %s: list of section keys */
			sprintf( __( 'Use one of: %s.', 'causeway-claude-bridge' ), implode( ', ', array_keys( $all ) ) )
		);
	}

	/**
	 * Key of the section a post belongs to, or null if its post type isn't in an enabled section.
	 *
	 * @param WP_Post $post Post.
	 */
	public function key_for_post( WP_Post $post ): ?string {
		foreach ( $this->all() as $key => $section ) {
			if ( $section['post_type'] === $post->post_type ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Loads a post Claude may work on: it exists and its post type is in an enabled section.
	 *
	 * @param int $post_id Post ID.
	 * @return array{0: WP_Post, 1: string}|WP_Error The post and its section key.
	 */
	public function post( int $post_id ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status || 'inherit' === $post->post_status ) {
			return AbilityError::make(
				'ccb_post_not_found',
				/* translators: %d: post ID */
				sprintf( __( 'There is no post with ID %d.', 'causeway-claude-bridge' ), $post_id ),
				404,
				'post_id',
				__( 'Check the ID with list-posts.', 'causeway-claude-bridge' )
			);
		}
		$key = $this->key_for_post( $post );
		if ( null === $key ) {
			return AbilityError::make(
				'ccb_post_type_not_allowed',
				__( 'That post isn\'t in a section Claude can work on.', 'causeway-claude-bridge' ),
				403,
				'post_id',
				__( 'Edit it in WP Admin instead.', 'causeway-claude-bridge' )
			);
		}
		return array( $post, $key );
	}
}
