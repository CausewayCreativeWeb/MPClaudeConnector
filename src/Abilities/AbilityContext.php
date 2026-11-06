<?php
/**
 * Per-call context collected for the activity log.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Abilities;

use WP_Post;

/**
 * Abilities fill this in as they work, so the one activity row per call says which post,
 * section and status change it was about, even when the call ends in an error.
 */
final class AbilityContext {

	/**
	 * Post the call was about.
	 *
	 * @var int|null
	 */
	public ?int $post_id = null;

	/**
	 * Attachment the call was about.
	 *
	 * @var int|null
	 */
	public ?int $attachment_id = null;

	/**
	 * Section key.
	 *
	 * @var string|null
	 */
	public ?string $section = null;

	/**
	 * Post title at the time.
	 *
	 * @var string|null
	 */
	public ?string $title = null;

	/**
	 * Status before.
	 *
	 * @var string|null
	 */
	public ?string $status_from = null;

	/**
	 * Status after.
	 *
	 * @var string|null
	 */
	public ?string $status_to = null;

	/**
	 * Extra details for the log (never content or secrets).
	 *
	 * @var array<string, mixed>
	 */
	public array $details = array();

	/**
	 * Records the post and section the call is about.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $section Section key.
	 */
	public function about_post( WP_Post $post, string $section ): void {
		$this->post_id     = $post->ID;
		$this->section     = $section;
		$this->title       = $post->post_title;
		$this->status_from = $post->post_status;
	}

	/**
	 * Activity log columns.
	 *
	 * @return array<string, mixed>
	 */
	public function audit_columns(): array {
		return array(
			'post_id'             => $this->post_id,
			'attachment_id'       => $this->attachment_id,
			'section'             => $this->section,
			'post_title_snapshot' => $this->title,
			'status_from'         => $this->status_from,
			'status_to'           => $this->status_to,
		);
	}
}
