<?php
/**
 * Live changes: publish, schedule, update live, unpublish, move to the Bin (docs/03 "Live-change abilities").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Live;

use Causeway\ClaudeBridge\Abilities\AbilityContext;
use Causeway\ClaudeBridge\Abilities\AbilityError;
use Causeway\ClaudeBridge\Abilities\Sections;
use Causeway\ClaudeBridge\Checks\LiveUrlCheck;
use Causeway\ClaudeBridge\Checks\PostVerifier;
use Causeway\ClaudeBridge\Content\ContentSanitiser;
use Causeway\ClaudeBridge\Content\PostWriter;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\Security\ConfirmationTokens;
use Causeway\ClaudeBridge\Security\RateLimiter;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WP_Error;
use WP_Post;

/**
 * Both halves of every live change. `prepare()` runs every check the action would run, issues a
 * token and returns a plain-English summary; it changes nothing. `apply()` uses up the token,
 * runs the same checks again on the current post, then acts.
 */
final class LiveChanges {

	public const PUBLISH     = 'publish';
	public const SCHEDULE    = 'schedule';
	public const UPDATE_LIVE = 'update_live';
	public const UNPUBLISH   = 'unpublish';
	public const TRASH       = 'trash';

	/**
	 * Statuses each action may start from.
	 */
	private const FROM = array(
		self::PUBLISH     => array( 'draft', 'pending' ),
		self::SCHEDULE    => array( 'draft', 'pending', 'future' ),
		self::UPDATE_LIVE => array( 'publish', 'future' ),
		self::UNPUBLISH   => array( 'publish', 'future' ),
		self::TRASH       => array( 'draft', 'pending', 'future', 'publish', 'private' ),
	);

	/**
	 * Minimum lead time for a schedule (docs/03).
	 */
	public const SCHEDULE_LEAD = 2 * MINUTE_IN_SECONDS;

	/**
	 * Timezone for schedule times sent without an offset (docs/03 "Dates").
	 */
	public const DEFAULT_TIMEZONE = 'Europe/London';

	/**
	 * Sections.
	 *
	 * @var Sections
	 */
	private Sections $sections;

	/**
	 * Sets up the service.
	 *
	 * @param Sections|null $sections Sections.
	 */
	public function __construct( ?Sections $sections = null ) {
		$this->sections = $sections ?? new Sections();
	}

	/**
	 * Whether an action needs `ccb_publish`.
	 *
	 * @param string $action Action.
	 */
	public static function needs_publish_capability( string $action ): bool {
		return in_array( $action, array( self::PUBLISH, self::SCHEDULE ), true );
	}

	/**
	 * Checks an action and returns a summary and token, without changing anything.
	 *
	 * @param string               $action  Action.
	 * @param array<string, mixed> $input   Ability input.
	 * @param AbilityContext       $context Audit context.
	 * @return array<string, mixed>|WP_Error
	 */
	public function prepare( string $action, array $input, AbilityContext $context ): array|WP_Error {
		$found = $this->load( (int) $input['post_id'], $context );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		[ $post, $key ] = $found;

		$plan = $this->check( $action, $post, $key, $input );
		if ( $plan instanceof WP_Error ) {
			return $plan;
		}

		$token              = ConfirmationTokens::issue( get_current_user_id(), $post->ID, $action, self::payload( $action, $input ), $post->post_modified_gmt );
		$context->status_to = $plan['summary']['to_status'];
		$context->details   = array(
			'prepared' => $action,
			'changes'  => array_column( $plan['summary']['changes'], 'field' ),
		);

		return array(
			'summary'        => $plan['summary'],
			'token'          => $token['token'],
			'expires_at_gmt' => $token['expires_at_gmt'],
		);
	}

	/**
	 * Uses up the token, checks again, and makes the change.
	 *
	 * @param string               $action  Action.
	 * @param array<string, mixed> $input   Ability input including `token`.
	 * @param AbilityContext       $context Audit context.
	 * @return array<string, mixed>|WP_Error
	 */
	public function apply( string $action, array $input, AbilityContext $context ): array|WP_Error {
		$found = $this->load( (int) $input['post_id'], $context );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		[ $post, $key ] = $found;

		$token_problem = ConfirmationTokens::consume( (string) ( $input['token'] ?? '' ), get_current_user_id(), $post->ID, $action, self::payload( $action, $input ), $post->post_modified_gmt );
		if ( null !== $token_problem ) {
			return $token_problem;
		}

		$plan = $this->check( $action, $post, $key, $input );
		if ( $plan instanceof WP_Error ) {
			return $plan;
		}

		$old_url = 'publish' === $post->post_status ? (string) get_permalink( $post ) : '';
		$done    = $this->act( $action, $post, $plan );
		if ( $done instanceof WP_Error ) {
			return $done;
		}

		RateLimiter::record_live_change( get_current_user_id() );
		clean_post_cache( $post->ID );
		$after              = get_post( $post->ID );
		$status             = $after instanceof WP_Post ? $after->post_status : (string) $plan['summary']['to_status'];
		$context->status_to = $status;
		$context->details   = array( 'changes' => array_column( $plan['summary']['changes'], 'field' ) );
		if ( self::TRASH !== $action ) {
			PostWriter::mark_change( $post->ID, $action );
		}

		/**
		 * Fires after a confirmed live change (notifications hook on in M7).
		 *
		 * @param string               $action  publish, schedule, update_live, unpublish or trash.
		 * @param int                  $post_id Post.
		 * @param array<string, mixed> $summary The summary the user confirmed.
		 */
		do_action( 'ccb_live_change', $action, $post->ID, $plan['summary'] );

		$section      = $this->sections->all()[ $key ];
		$verification = self::TRASH === $action
			? array(
				'passed' => 'trash' === $status,
				'checks' => array(
					array(
						'name'    => 'status',
						'passed'  => 'trash' === $status,
						'message' => 'trash' === $status ? __( 'Moved to the Bin.', 'causeway-claude-bridge' ) : __( 'The post is not in the Bin.', 'causeway-claude-bridge' ),
					),
				),
			)
			: PostVerifier::verify( $post->ID, $section, $plan['guide'], $this->sections->config()['writing'], array( 'status' => $plan['summary']['to_status'] ) );

		// Check the public page: live after publish/update, gone after unpublish/Bin (docs/07).
		$url_check = null;
		if ( in_array( $action, array( self::PUBLISH, self::UPDATE_LIVE ), true ) && 'publish' === $status && $after instanceof WP_Post ) {
			$url_check = LiveUrlCheck::check( (string) get_permalink( $after ), true, LiveUrlCheck::purge( $post->ID ) );
		} elseif ( in_array( $action, array( self::UNPUBLISH, self::TRASH ), true ) && '' !== $old_url ) {
			$url_check = LiveUrlCheck::check( $old_url, false, LiveUrlCheck::purge( $post->ID ) );
		}
		if ( null !== $url_check ) {
			$verification['checks'][] = $url_check;
			$verification['passed']   = $verification['passed'] && $url_check['passed'];
		}

		return array(
			'post_id'      => $post->ID,
			'status'       => $status,
			'url'          => $after instanceof WP_Post && 'publish' === $status ? get_permalink( $after ) : null,
			'verification' => $verification,
			'warnings'     => $plan['warnings'],
		);
	}

	/**
	 * Restores a post from the Bin, always as a draft (no token: nothing goes live).
	 *
	 * @param int            $post_id Post.
	 * @param AbilityContext $context Audit context.
	 * @return array<string, mixed>|WP_Error
	 */
	public function restore( int $post_id, AbilityContext $context ): array|WP_Error {
		$found = $this->load( $post_id, $context );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		[ $post ] = $found;
		if ( 'trash' !== $post->post_status ) {
			return AbilityError::make( 'ccb_wrong_status', __( 'That post isn\'t in the Bin.', 'causeway-claude-bridge' ), 409, 'post_id' );
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return AbilityError::make( 'ccb_forbidden', __( 'You can\'t restore this post.', 'causeway-claude-bridge' ), 403, 'post_id' );
		}

		$force_draft = static fn (): string => 'draft';
		add_filter( 'wp_untrash_post_status', $force_draft, PHP_INT_MAX );
		$restored    = wp_untrash_post( $post->ID );
		remove_filter( 'wp_untrash_post_status', $force_draft, PHP_INT_MAX );
		if ( ! $restored instanceof WP_Post ) {
			return AbilityError::make( 'ccb_internal', __( 'WordPress couldn\'t restore the post.', 'causeway-claude-bridge' ), 500 );
		}
		PostWriter::mark_change( $post->ID, 'restore' );
		$context->status_to = 'draft';

		/**
		 * Fires after a post is restored from the Bin (notifications hook on in M7).
		 *
		 * @param int $post_id Post.
		 */
		do_action( 'ccb_post_restored', $post->ID );

		return array(
			'post_id'     => $post->ID,
			'status'      => get_post_status( $post->ID ),
			'preview_url' => get_preview_post_link( $post->ID ),
		);
	}

	/**
	 * Loads the post and records it on the context.
	 *
	 * @param int            $post_id Post.
	 * @param AbilityContext $context Context.
	 * @return array{0: WP_Post, 1: string}|WP_Error
	 */
	private function load( int $post_id, AbilityContext $context ): array|WP_Error {
		$found = $this->sections->post( $post_id );
		if ( $found instanceof WP_Error ) {
			return $found;
		}
		$context->about_post( $found[0], $found[1] );
		return $found;
	}

	/**
	 * Every check for an action against the post as it is now; returns what to do and the summary.
	 *
	 * @param string               $action Action.
	 * @param WP_Post              $post   Post.
	 * @param string               $key    Section key.
	 * @param array<string, mixed> $input  Input.
	 * @return array{summary: array<string, mixed>, guide: array<string, mixed>, warnings: list<string>, prepared?: array<string, mixed>, publish_at?: int}|WP_Error
	 */
	private function check( string $action, WP_Post $post, string $key, array $input ): array|WP_Error {
		$section = $this->sections->all()[ $key ];
		$config  = $this->sections->config();
		$writer  = new PostWriter( $this->sections );
		$guide   = $writer->guide( $key );

		if ( ! in_array( $post->post_status, self::FROM[ $action ], true ) ) {
			return self::wrong_status( $action, $post->post_status );
		}

		$capability = self::TRASH === $action ? 'delete_post' : 'edit_post';
		if ( ! current_user_can( $capability, $post->ID ) ) {
			return AbilityError::make( 'ccb_forbidden', __( 'You can\'t change this post.', 'causeway-claude-bridge' ), 403, 'post_id' );
		}
		if ( self::needs_publish_capability( $action ) && ! current_user_can( RoleManager::CAP_PUBLISH ) ) {
			return AbilityError::make( 'ccb_forbidden', __( 'Only AI Publishers can publish or schedule posts.', 'causeway-claude-bridge' ), 403, null, __( 'Submit the post for review instead.', 'causeway-claude-bridge' ) );
		}

		$retry = RateLimiter::check_live_change( get_current_user_id(), (int) $config['access']['live_changes_per_day'] );
		if ( $retry > 0 ) {
			return AbilityError::make(
				'ccb_rate_limited',
				/* translators: %d: changes per day */
				sprintf( __( 'You\'ve reached today\'s limit of %d changes to the live site.', 'causeway-claude-bridge' ), (int) $config['access']['live_changes_per_day'] ),
				429,
				null,
				__( 'Try again tomorrow, or make the change in WP Admin.', 'causeway-claude-bridge' ),
				null,
				array( 'retry_after' => $retry )
			);
		}

		$plan = array(
			'guide'    => $guide,
			'warnings' => array(),
		);
		$to   = match ( $action ) {
			self::PUBLISH     => 'publish',
			self::SCHEDULE    => 'future',
			self::UNPUBLISH   => 'draft',
			self::TRASH       => 'trash',
			default           => $post->post_status,
		};
		$changes = array();
		$notes   = array();

		switch ( $action ) {
			case self::PUBLISH:
			case self::SCHEDULE:
				$missing = self::missing_requirements( $post->ID, $section, $guide, $config['writing'] );
				if ( array() !== $missing ) {
					return AbilityError::make(
						'ccb_required_missing',
						/* translators: %s: list of problems */
						sprintf( __( 'This post isn\'t ready to go live: %s', 'causeway-claude-bridge' ), implode( ' ', $missing ) ),
						400,
						null,
						__( 'Fix these with update-post first, then prepare again.', 'causeway-claude-bridge' )
					);
				}
				if ( self::SCHEDULE === $action ) {
					$when = self::parse_time( (string) ( $input['publish_at'] ?? '' ) );
					if ( null === $when ) {
						return AbilityError::make( 'ccb_invalid_input', __( 'The schedule time isn\'t a date and time I can read.', 'causeway-claude-bridge' ), 400, 'publish_at', __( 'Use ISO 8601, e.g. 2026-11-18T09:00:00+00:00.', 'causeway-claude-bridge' ) );
					}
					if ( $when < time() + self::SCHEDULE_LEAD ) {
						return AbilityError::make( 'ccb_schedule_in_past', __( 'That time has passed or is less than 2 minutes away.', 'causeway-claude-bridge' ), 400, 'publish_at', __( 'Ask the user for a later time.', 'causeway-claude-bridge' ) );
					}
					$plan['publish_at'] = $when;
				}
				break;

			case self::UPDATE_LIVE:
				$requested = is_array( $input['changes'] ?? null ) ? $input['changes'] : array();
				unset( $requested['status'] );
				if ( array() === $requested ) {
					return AbilityError::make( 'ccb_invalid_input', __( 'No changes were sent.', 'causeway-claude-bridge' ), 400, 'changes' );
				}
				$prepared = $writer->prepare( $requested, $section, $guide, $post );
				if ( $prepared instanceof WP_Error ) {
					return $prepared;
				}
				$plan['prepared'] = $prepared;
				$plan['warnings'] = $prepared['warnings'];
				$changes          = self::describe_changes( $post, $prepared, $guide );
				$notes[]          = __( 'A revision of the current version is saved first.', 'causeway-claude-bridge' );
				break;

			case self::TRASH:
				$days = defined( 'EMPTY_TRASH_DAYS' ) ? (int) EMPTY_TRASH_DAYS : 30;
				if ( $days <= 0 ) {
					return AbilityError::make( 'ccb_forbidden', __( 'The Bin is switched off on this site, so moving to the Bin would delete the post permanently.', 'causeway-claude-bridge' ), 403, null, __( 'Unpublish it instead, or ask an administrator.', 'causeway-claude-bridge' ) );
				}
				/* translators: %d: days */
				$notes[] = sprintf( _n( 'Can be restored for %d day.', 'Can be restored for %d days.', $days, 'causeway-claude-bridge' ), $days );
				break;
		}

		if ( self::will_email( $action, $config ) ) {
			$notes[] = __( 'Publishers will be emailed.', 'causeway-claude-bridge' );
		}

		$author          = get_userdata( (int) $post->post_author );
		$plan['summary'] = array(
			'action'      => self::action_words( $action, $plan['publish_at'] ?? null ),
			'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
			'section'     => $section['label'],
			'author'      => false === $author ? '' : $author->display_name,
			'from_status' => $post->post_status,
			'to_status'   => $to,
			'url'         => in_array( $post->post_status, array( 'publish' ), true ) ? get_permalink( $post ) : get_preview_post_link( $post ),
			'changes'     => $changes,
			'notes'       => $notes,
		);
		return $plan;
	}

	/**
	 * Makes the change.
	 *
	 * @param string               $action Action.
	 * @param WP_Post              $post   Post.
	 * @param array<string, mixed> $plan   From check().
	 */
	private function act( string $action, WP_Post $post, array $plan ): ?WP_Error {
		switch ( $action ) {
			case self::PUBLISH:
				$now    = time();
				$result = wp_update_post(
					array(
						'ID'            => $post->ID,
						'post_status'   => 'publish',
						'post_date'     => (string) wp_date( 'Y-m-d H:i:s', $now ),
						'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $now ),
						'edit_date'     => true,
					),
					true
				);
				break;

			case self::SCHEDULE:
				$when   = (int) $plan['publish_at'];
				$result = wp_update_post(
					array(
						'ID'            => $post->ID,
						'post_status'   => 'future',
						'post_date'     => (string) wp_date( 'Y-m-d H:i:s', $when ),
						'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $when ),
						'edit_date'     => true,
					),
					true
				);
				break;

			case self::UPDATE_LIVE:
				wp_save_post_revision( $post->ID );
				$prepared = $plan['prepared'];
				$result   = array() === $prepared['post'] ? $post->ID : wp_update_post( wp_slash( array_merge( $prepared['post'], array( 'ID' => $post->ID ) ) ), true );
				if ( ! $result instanceof WP_Error ) {
					( new PostWriter( $this->sections ) )->write_extras( $post->ID, $prepared, $prepared['terms'] );
				}
				break;

			case self::UNPUBLISH:
				$result = wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_status' => 'draft',
					),
					true
				);
				break;

			case self::TRASH:
				$result = wp_trash_post( $post->ID );
				$result = $result instanceof WP_Post ? $post->ID : new WP_Error( 'trash_failed', 'wp_trash_post() failed' );
				break;

			default:
				$result = new WP_Error( 'unknown_action', $action );
		}

		if ( $result instanceof WP_Error ) {
			return AbilityError::make( 'ccb_internal', __( 'WordPress couldn\'t make the change.', 'causeway-claude-bridge' ), 500, null, $result->get_error_message() );
		}
		return null;
	}

	/**
	 * What must be in place before publishing or scheduling (required terms, fields, featured image).
	 *
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $section Section config.
	 * @param array<string, mixed> $guide   Guide entry.
	 * @param array<string, mixed> $writing Config writing block.
	 * @return list<string>
	 */
	public static function missing_requirements( int $post_id, array $section, array $guide, array $writing ): array {
		$verification = PostVerifier::verify( $post_id, $section, $guide, $writing );
		$missing      = array();
		foreach ( $verification['checks'] as $check ) {
			if ( ! $check['passed'] && preg_match( '/^(terms_|field_|featured_image)/', $check['name'] ) ) {
				$missing[] = $check['message'];
			}
		}
		$limits = $section['limits'];
		$post   = get_post( $post_id );
		if ( $post instanceof WP_Post && $limits['max_words'] > 0 && ContentSanitiser::word_count( $post->post_content ) > $limits['max_words'] ) {
			/* translators: %d: maximum words */
			$missing[] = sprintf( __( 'The post is longer than %d words.', 'causeway-claude-bridge' ), $limits['max_words'] );
		}
		return $missing;
	}

	/**
	 * The part of the input the token is bound to.
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $input  Input.
	 * @return array<string, mixed>
	 */
	public static function payload( string $action, array $input ): array {
		return match ( $action ) {
			self::SCHEDULE    => array( 'publish_at' => self::parse_time( (string) ( $input['publish_at'] ?? '' ) ) ?? (string) ( $input['publish_at'] ?? '' ) ),
			self::UPDATE_LIVE => array( 'changes' => is_array( $input['changes'] ?? null ) ? $input['changes'] : array() ),
			default           => array(),
		};
	}

	/**
	 * Unix time from ISO 8601; times without an offset are read as UK time.
	 *
	 * @param string $value Time.
	 */
	public static function parse_time( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?/', $value ) ) {
			return null;
		}
		try {
			$has_offset = 1 === preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $value );
			$date       = new DateTimeImmutable( $value, $has_offset ? null : new DateTimeZone( self::DEFAULT_TIMEZONE ) );
		} catch ( Exception $e ) {
			return null;
		}
		return $date->getTimestamp();
	}

	/**
	 * The action in plain words.
	 *
	 * @param string   $action Action.
	 * @param int|null $when   Schedule time.
	 */
	private static function action_words( string $action, ?int $when ): string {
		switch ( $action ) {
			case self::PUBLISH:
				return __( 'Publish now', 'causeway-claude-bridge' );
			case self::SCHEDULE:
				$timezone = wp_timezone_string();
				$zone     = 'Europe/London' === $timezone ? __( 'UK time', 'causeway-claude-bridge' ) : $timezone;
				/* translators: 1: date and time, 2: timezone name */
				return sprintf( __( 'Schedule for %1$s %2$s', 'causeway-claude-bridge' ), (string) wp_date( 'D j M Y, H:i', (int) $when ), $zone );
			case self::UPDATE_LIVE:
				return __( 'Update the live post', 'causeway-claude-bridge' );
			case self::UNPUBLISH:
				return __( 'Unpublish (back to draft)', 'causeway-claude-bridge' );
			default:
				return __( 'Move to the Bin', 'causeway-claude-bridge' );
		}
	}

	/**
	 * What an update to a live post changes, field by field.
	 *
	 * @param WP_Post              $post     Post.
	 * @param array<string, mixed> $prepared From PostWriter::prepare().
	 * @param array<string, mixed> $guide    Guide entry.
	 * @return list<array{field: string, before: string, after: string}>
	 */
	private static function describe_changes( WP_Post $post, array $prepared, array $guide ): array {
		$changes = array();
		$short   = static fn ( string $text ): string => mb_strlen( $text ) > 120 ? mb_substr( $text, 0, 117 ) . '…' : $text;

		$labels = array(
			'post_title'   => __( 'Title', 'causeway-claude-bridge' ),
			'post_excerpt' => __( 'Excerpt', 'causeway-claude-bridge' ),
			'post_name'    => __( 'Slug', 'causeway-claude-bridge' ),
		);
		foreach ( $labels as $column => $label ) {
			if ( isset( $prepared['post'][ $column ] ) && (string) $prepared['post'][ $column ] !== (string) $post->$column ) {
				$changes[] = array(
					'field'  => $label,
					'before' => $short( (string) $post->$column ),
					'after'  => $short( (string) $prepared['post'][ $column ] ),
				);
			}
		}
		if ( isset( $prepared['post']['post_content'] ) && $prepared['post']['post_content'] !== $post->post_content ) {
			$changes[] = array(
				'field'  => __( 'Body', 'causeway-claude-bridge' ),
				/* translators: %d: word count */
				'before' => sprintf( __( '%d words', 'causeway-claude-bridge' ), ContentSanitiser::word_count( $post->post_content ) ),
				/* translators: %d: word count */
				'after'  => sprintf( __( '%d words', 'causeway-claude-bridge' ), ContentSanitiser::word_count( (string) $prepared['post']['post_content'] ) ),
			);
		}
		foreach ( $prepared['terms'] as $taxonomy => $ids ) {
			$current = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			$current = is_array( $current ) ? $current : array();
			$names   = array_map( static fn ( int $id ): string => (string) get_term_field( 'name', $id, $taxonomy ), $ids );
			sort( $current );
			sort( $names );
			if ( $current !== $names ) {
				$changes[] = array(
					'field'  => (string) ( $guide['taxonomies'][ $taxonomy ]['label'] ?? $taxonomy ),
					'before' => implode( ', ', $current ),
					'after'  => implode( ', ', $names ),
				);
			}
		}
		$fields = array_column( $guide['fields'], 'label', 'name' );
		foreach ( $prepared['fields'] as $write ) {
			$changes[] = array(
				'field'  => (string) ( $fields[ $write['name'] ] ?? $write['name'] ),
				'before' => '',
				'after'  => is_scalar( $write['value'] ) ? $short( (string) $write['value'] ) : __( '(changed)', 'causeway-claude-bridge' ),
			);
		}
		$current_image = (int) get_post_thumbnail_id( $post );
		if ( null !== $prepared['featured_image'] && $current_image !== $prepared['featured_image'] ) {
			$changes[] = array(
				'field'  => __( 'Featured image', 'causeway-claude-bridge' ),
				'before' => (string) get_the_title( $current_image ),
				'after'  => $prepared['featured_image'] > 0 ? (string) get_the_title( $prepared['featured_image'] ) : __( '(removed)', 'causeway-claude-bridge' ),
			);
		}
		foreach ( $prepared['seo'] as $meta_key => $value ) {
			$changes[] = array(
				'field'  => __( 'SEO', 'causeway-claude-bridge' ),
				'before' => $short( (string) get_post_meta( $post->ID, $meta_key, true ) ),
				'after'  => $short( $value ),
			);
		}
		return $changes;
	}

	/**
	 * Whether Publishers get an email for this action (docs/07).
	 *
	 * @param string               $action Action.
	 * @param array<string, mixed> $config Config.
	 */
	private static function will_email( string $action, array $config ): bool {
		$events = $config['notifications']['events'];
		$event  = self::TRASH === $action ? 'removal' : 'live_change';
		return ! empty( $events[ $event ] ) && ( ! empty( $config['notifications']['recipients']['publishers'] ) || array() !== $config['notifications']['recipients']['extra'] );
	}

	/**
	 * Wrong starting status.
	 *
	 * @param string $action Action.
	 * @param string $status Current status.
	 */
	private static function wrong_status( string $action, string $status ): WP_Error {
		$fix = match ( $action ) {
			self::UPDATE_LIVE => __( 'Use update-post for drafts and pending posts.', 'causeway-claude-bridge' ),
			self::UNPUBLISH   => __( 'Only published or scheduled posts can be unpublished.', 'causeway-claude-bridge' ),
			self::TRASH       => __( 'It\'s already in the Bin. Use restore-post to bring it back.', 'causeway-claude-bridge' ),
			default           => __( 'Check the post\'s status with get-post.', 'causeway-claude-bridge' ),
		};
		return AbilityError::make(
			'ccb_wrong_status',
			/* translators: %s: post status */
			sprintf( __( 'This can\'t be done while the post is %s.', 'causeway-claude-bridge' ), $status ),
			409,
			'post_id',
			$fix
		);
	}
}
