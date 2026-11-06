<?php
/**
 * `ccb/publish-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Publish post.
 */
final class PublishPost extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/publish-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Publish post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 2 of publishing: publishes the post now. Needs the token from prepare-publish, sent only after the user clearly said yes to its summary. Show the result and the verification checklist. Never retry after a server error or timeout: check with get-post.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function action(): string {
		return LiveChanges::PUBLISH;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function is_prepare(): bool {
		return false;
	}
}
