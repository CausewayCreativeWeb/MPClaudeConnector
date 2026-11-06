<?php
/**
 * `ccb/prepare-schedule` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Prepare to schedule.
 */
final class PrepareSchedule extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/prepare-schedule';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Prepare to schedule', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 1 of scheduling or rescheduling (AI Publishers only). publish_at is ISO 8601; times without an offset are UK time; it must be at least 2 minutes ahead. Returns a summary and a token. Show the summary in full and only continue on a clear yes from the user.', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function action(): string {
		return LiveChanges::SCHEDULE;
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
		return array(
			'publish_at' => array(
				'type'        => 'string',
				'description' => __( 'When to publish, ISO 8601 (e.g. 2026-11-18T09:00:00+00:00). Without an offset it is UK time.', 'causeway-claude-bridge' ),
				'maxLength'   => 40,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function extra_required(): array {
		return array( 'publish_at' );
	}
}
