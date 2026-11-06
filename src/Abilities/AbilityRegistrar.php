<?php
/**
 * Registers the `ccb` ability category and abilities with the Abilities API.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

/**
 * The one place that lists every ability Claude can call (docs/03). Nothing else is registered.
 */
final class AbilityRegistrar {

	public const CATEGORY = 'ccb';

	/**
	 * Ability classes, in the order Claude sees them.
	 *
	 * @var list<class-string<AbstractAbility>>
	 */
	private const ABILITIES = array(
		CheckConnection::class,
		GetPublishingGuide::class,
		ListSections::class,
		ListTerms::class,
		ListPosts::class,
		GetPost::class,
	);

	/**
	 * Instances, built once.
	 *
	 * @var list<AbstractAbility>|null
	 */
	private static ?array $instances = null;

	/**
	 * Hooks into the Abilities API.
	 */
	public static function register_hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Claude Publishing', 'causeway-claude-bridge' ),
				'description' => __( 'Draft, edit, publish and remove posts on this site from Claude.', 'causeway-claude-bridge' ),
			)
		);
	}

	/**
	 * Registers every ability.
	 */
	public static function register_abilities(): void {
		foreach ( self::abilities() as $ability ) {
			wp_register_ability( $ability->name(), $ability->registration_args() );
		}
	}

	/**
	 * Ability instances.
	 *
	 * @return list<AbstractAbility>
	 */
	public static function abilities(): array {
		if ( null === self::$instances ) {
			self::$instances = array();
			foreach ( self::ABILITIES as $class ) {
				self::$instances[] = new $class();
			}
		}
		return self::$instances;
	}

	/**
	 * Ability names, for the MCP server's tool list.
	 *
	 * @return list<string>
	 */
	public static function names(): array {
		return array_map( static fn ( AbstractAbility $ability ): string => $ability->name(), self::abilities() );
	}
}
