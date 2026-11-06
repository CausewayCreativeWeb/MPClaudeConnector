<?php
/**
 * `ccb/unpublish-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Unpublish post.
 */
final class UnpublishPost extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/unpublish-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Unpublish post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 2 of unpublishing: moves the post back to draft. Needs the token from prepare-unpublish, sent only after the user clearly said yes. Never retry after a server error or timeout: check with get-post.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function action(): string {
		return LiveChanges::UNPUBLISH;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_prepare(): bool {
		return false;
	}
}
