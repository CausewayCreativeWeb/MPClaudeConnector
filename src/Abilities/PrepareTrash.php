<?php
/**
 * `ccb/prepare-trash` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Prepare to move to the Bin.
 */
final class PrepareTrash extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/prepare-trash';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Prepare to move to the Bin', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 1 of moving a post to the Bin, where it can be restored for a number of days. Nothing is ever deleted permanently. Returns a summary and a token. Show it in full and only continue on a clear yes from the user.', 'causeway-claude-bridge' );
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
		return true;
	}
}
