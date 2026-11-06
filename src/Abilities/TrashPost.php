<?php
/**
 * `ccb/trash-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Move post to the Bin.
 */
final class TrashPost extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/trash-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Move post to the Bin', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 2 of moving a post to the Bin. Needs the token from prepare-trash, sent only after the user clearly said yes. Never retry after a server error or timeout: check with get-post.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function action(): string {
		return LiveChanges::TRASH;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_prepare(): bool {
		return false;
	}
}
