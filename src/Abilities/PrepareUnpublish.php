<?php
/**
 * `ccb/prepare-unpublish` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Prepare to unpublish.
 */
final class PrepareUnpublish extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/prepare-unpublish';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Prepare to unpublish', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 1 of taking a published or scheduled post off the site (it goes back to draft). Returns a summary and a token. Show it in full and only continue on a clear yes from the user.', 'causeway-claude-bridge' );
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
		return true;
	}
}
