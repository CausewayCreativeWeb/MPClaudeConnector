<?php
/**
 * `ccb/prepare-update-live` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Prepare to update a live post.
 */
final class PrepareUpdateLive extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/prepare-update-live';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Prepare to update a live post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 1 of changing a published or scheduled post. Send only the fields that change, in changes. Returns a summary listing each change (before → after) and a token. Show it in full and only continue on a clear yes from the user.', 'causeway-claude-bridge' );
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
		return true;
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
