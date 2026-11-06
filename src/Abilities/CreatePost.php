<?php
/**
 * `ccb/create-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Content\PostWriter;
use WP_Error;

/**
 * Creates a draft or pending post. Never publishes.
 */
final class CreatePost extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/create-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Create post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Creates a new post in a section, following the publishing guide. AI Contributors\' posts go to Pending Review; AI Publishers may choose draft or pending. It never publishes. Send a new idempotency_key with every new post, and show the user the verification checklist and preview link. If a server error or timeout happens, do not retry: check with list-posts first.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'create';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'required'             => array( 'section', 'title' ),
			'properties'           => array_merge(
				array(
					'section' => array(
						'type'    => 'string',
						'pattern' => '^[a-z][a-z0-9-]{1,30}$',
					),
				),
				PostInputSchema::properties(),
				array(
					'idempotency_key'   => array(
						'type'        => 'string',
						'pattern'     => '^[A-Za-z0-9_-]{8,64}$',
						'description' => __( 'A new random ID (e.g. a UUID) for each new post, reused only when retrying the same request.', 'causeway-claude-bridge' ),
					),
					'confirm_duplicate' => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => __( 'Set true only after the user confirms they want a second post with the same title.', 'causeway-claude-bridge' ),
					),
				)
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
		return ( new PostWriter() )->create( $input, $context );
	}
}
