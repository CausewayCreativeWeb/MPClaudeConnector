<?php
/**
 * `ccb/update-live-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Update live post.
 */
final class UpdateLivePost extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/update-live-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Update live post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 2 of changing a published or scheduled post. Send exactly the same changes as prepare-update-live, with its token, only after the user clearly said yes. A revision is saved first. Never retry after a server error or timeout: check with get-post.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function action(): string {
		return LiveChanges::UPDATE_LIVE;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_prepare(): bool {
		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function extra_properties(): array {
		$fields = PostInputSchema::properties();
		unset( $fields['status'] );
		return array(
			'changes' => array(
				'type'                 => 'object',
				'description'          => __( 'Only the fields that change: title, content, excerpt, slug, terms, fields, featured_image_id, seo.', 'causeway-claude-bridge' ),
				'properties'           => $fields,
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function extra_required(): array {
		return array( 'changes' );
	}
}
