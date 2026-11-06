<?php
/**
 * `ccb/schedule-post` (docs/03).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use Causeway\ClaudeBridge\Live\LiveChanges;

/**
 * Schedule post.
 */
final class SchedulePost extends AbstractLiveAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'ccb/schedule-post';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Schedule post', 'causeway-claude-bridge' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Step 2 of scheduling: schedules the post. Send the same publish_at as prepare-schedule and its token, only after the user clearly said yes. Never retry after a server error or timeout: check with get-post.', 'causeway-claude-bridge' );
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
		return false;
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
