<?php
/**
 * `ccb/get-publishing-guide` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Config\PublishingGuide;
use WP_Error;

/**
 * The rules Claude follows when writing: terms, fields, images, limits and house style, per section.
 */
final class GetPublishingGuide extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/get-publishing-guide';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Get publishing guide', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Returns the rules for writing posts: for each section, the categories and terms you may use (never invent new ones), required and optional fields, featured image rules and length limits, plus a plain-English guide to follow exactly. Call it before drafting, for the section you are working in.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'guide';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'section' => array(
					'type'        => 'string',
					'description' => __( 'Section key from check-connection. Omit for all sections.', 'causeway-claude-bridge' ),
					'pattern'     => '^[a-z][a-z0-9-]{1,30}$',
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
			'required'   => array( 'config_version', 'general', 'sections' ),
			'properties' => array(
				'config_version' => array( 'type' => 'integer' ),
				'general'        => array( 'type' => 'object' ),
				'sections'       => array( 'type' => 'array' ),
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
		$key      = isset( $input['section'] ) ? (string) $input['section'] : null;
		if ( null !== $key ) {
			$section = $sections->get( $key );
			if ( $section instanceof WP_Error ) {
				return $section;
			}
			$context->section = $key;
		}
		return PublishingGuide::build( $sections->config(), $sections->structure(), $key );
	}
}
