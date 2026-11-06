<?php
/**
 * `ccb/list-sections` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

/**
 * The sections and how many posts each has in each status.
 */
final class ListSections extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/list-sections';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'List sections', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Lists the sections you can post to, with how many posts each has as drafts, pending review, scheduled and published.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function audit_action(): string {
		return 'list';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
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
			'required'   => array( 'sections' ),
			'properties' => array(
				'sections' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'key'       => array( 'type' => 'string' ),
							'label'     => array( 'type' => 'string' ),
							'post_type' => array( 'type' => 'string' ),
							'counts'    => array( 'type' => 'object' ),
						),
					),
				),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input   Validated input.
	 * @param AbilityContext       $context Request context.
	 * @return array<string, mixed>
	 */
	protected function run( array $input, AbilityContext $context ): array {
		$list = array();
		foreach ( ( new Sections() )->all() as $key => $section ) {
			$counts = wp_count_posts( $section['post_type'] );
			$list[] = array(
				'key'       => $key,
				'label'     => $section['label'],
				'post_type' => $section['post_type'],
				'counts'    => array(
					'draft'   => (int) ( $counts->draft ?? 0 ),
					'pending' => (int) ( $counts->pending ?? 0 ),
					'future'  => (int) ( $counts->future ?? 0 ),
					'publish' => (int) ( $counts->publish ?? 0 ),
				),
			);
		}
		return array( 'sections' => $list );
	}
}
