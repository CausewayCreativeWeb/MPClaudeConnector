<?php
/**
 * `ccb/update-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Content\PostWriter;
use WP_Error;

/**
 * Edits a draft or pending post. Live and scheduled posts go through prepare-update-live.
 */
final class UpdatePost extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/update-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Update post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Edits a draft or pending post. Send only what changes. Pass expected_modified_gmt from get-post so someone else\'s changes aren\'t overwritten. Published and scheduled posts must use prepare-update-live instead. Content of Elementor-built posts can only be edited in WP Admin.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'update';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'post_id' ),
			'properties'           => array_merge(
				array(
					'post_id'               => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'expected_modified_gmt' => array(
						'type'        => 'string',
						'description' => __( 'modified_gmt from get-post; the update is refused if the post has changed since.', 'causeway-claude-bridge' ),
					),
				),
				PostInputSchema::properties()
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return PostInputSchema::output();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param AbilityContext       $context Request context.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function run( array $input, AbilityContext $context ): array|WP_Error {
		return ( new PostWriter() )->update( $input, $context );
	}
}
