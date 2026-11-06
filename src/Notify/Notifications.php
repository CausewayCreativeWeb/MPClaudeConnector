<?php
/**
 * Who gets which email (docs/07 "Notifications").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Notify;

use Causeway\ClaudeBridge\Abilities\Sections;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Live\LiveChanges;
use WP_Post;

/**
 * Turns Bridge events into emails, following the config's recipients and event switches:
 * - new pending post → Publishers (+ extra);
 * - publish, schedule, update live, unpublish → Publishers (+ extra);
 * - move to the Bin → Publishers (+ extra), with a link to the Bin list (no nonce: nonces expire);
 * - restore → Publishers.
 * The person who made the change is never emailed about it.
 */
final class Notifications {

	/**
	 * Hooks the events.
	 */
	public static function register_hooks(): void {
		add_action( 'ccb_post_created', array( self::class, 'on_created' ), 10, 2 );
		add_action( 'ccb_post_updated', array( self::class, 'on_updated' ), 10, 3 );
		add_action( 'ccb_live_change', array( self::class, 'on_live_change' ), 10, 3 );
		add_action( 'ccb_post_restored', array( self::class, 'on_restored' ) );
	}

	/**
	 * New post saved as pending.
	 *
	 * @param int    $post_id Post.
	 * @param string $status  Status.
	 */
	public static function on_created( int $post_id, string $status ): void {
		if ( 'pending' === $status ) {
			self::pending( $post_id );
		}
	}

	/**
	 * Draft moved to pending.
	 *
	 * @param int    $post_id     Post.
	 * @param string $status      Status after.
	 * @param string $status_from Status before.
	 */
	public static function on_updated( int $post_id, string $status, string $status_from = '' ): void {
		if ( 'pending' === $status && 'pending' !== $status_from ) {
			self::pending( $post_id );
		}
	}

	/**
	 * Confirmed live change.
	 *
	 * @param string               $action  Action.
	 * @param int                  $post_id Post.
	 * @param array<string, mixed> $summary Summary the user confirmed.
	 */
	public static function on_live_change( string $action, int $post_id, array $summary ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$event = LiveChanges::TRASH === $action ? 'removal' : 'live_change';
		$to    = self::recipients( $event, true );
		if ( array() === $to ) {
			return;
		}

		$rows = self::rows( $post, $summary );
		foreach ( (array) ( $summary['changes'] ?? array() ) as $change ) {
			$rows[ (string) $change['field'] ] = trim( $change['before'] . ' → ' . $change['after'], ' →' );
		}

		if ( LiveChanges::TRASH === $action ) {
			Mailer::queue(
				$to,
				/* translators: %s: post title */
				sprintf( __( 'Moved to the Bin: %s', 'causeway-claude-bridge' ), $summary['title'] ),
				array(
					/* translators: %s: person's name */
					'heading' => sprintf( __( '%s moved a post to the Bin', 'causeway-claude-bridge' ), self::actor() ),
					'intro'   => __( 'It can be restored from the Bin in WP Admin with one click.', 'causeway-claude-bridge' ),
					'rows'    => $rows,
					'link'    => array(
						'url'   => admin_url( 'edit.php?post_status=trash&post_type=' . rawurlencode( $post->post_type ) ),
						'label' => __( 'Open the Bin', 'causeway-claude-bridge' ),
					),
					'footer'  => self::footer(),
				)
			);
			return;
		}

		$url = 'publish' === $post->post_status ? get_permalink( $post ) : get_edit_post_link( $post->ID, 'raw' );
		Mailer::queue(
			$to,
			/* translators: 1: action, 2: post title */
			sprintf( __( '%1$s: %2$s', 'causeway-claude-bridge' ), $summary['action'], $summary['title'] ),
			array(
				/* translators: 1: person's name, 2: action in lower case */
				'heading' => sprintf( __( '%1$s changed the live site: %2$s', 'causeway-claude-bridge' ), self::actor(), $summary['action'] ),
				'rows'    => $rows,
				'link'    => array(
					'url'   => (string) $url,
					'label' => 'publish' === $post->post_status ? __( 'View the post', 'causeway-claude-bridge' ) : __( 'Open in WP Admin', 'causeway-claude-bridge' ),
				),
				'footer'  => self::footer(),
			)
		);
	}

	/**
	 * Post restored from the Bin (as a draft).
	 *
	 * @param int $post_id Post.
	 */
	public static function on_restored( int $post_id ): void {
		$post = get_post( $post_id );
		$to   = self::recipients( 'removal', false );
		if ( ! $post instanceof WP_Post || array() === $to ) {
			return;
		}
		Mailer::queue(
			$to,
			/* translators: %s: post title */
			sprintf( __( 'Restored from the Bin: %s', 'causeway-claude-bridge' ), get_the_title( $post ) ),
			array(
				/* translators: %s: person's name */
				'heading' => sprintf( __( '%s restored a post from the Bin', 'causeway-claude-bridge' ), self::actor() ),
				'intro'   => __( 'It is now a draft and isn\'t on the live site.', 'causeway-claude-bridge' ),
				'rows'    => self::rows( $post, array() ),
				'link'    => array(
					'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
					'label' => __( 'Open in WP Admin', 'causeway-claude-bridge' ),
				),
				'footer'  => self::footer(),
			)
		);
	}

	/**
	 * Email for a post waiting for review.
	 *
	 * @param int $post_id Post.
	 */
	private static function pending( int $post_id ): void {
		$post = get_post( $post_id );
		$to   = self::recipients( 'pending_post', true );
		if ( ! $post instanceof WP_Post || array() === $to ) {
			return;
		}
		Mailer::queue(
			$to,
			/* translators: %s: post title */
			sprintf( __( 'Waiting for review: %s', 'causeway-claude-bridge' ), get_the_title( $post ) ),
			array(
				/* translators: %s: person's name */
				'heading' => sprintf( __( '%s submitted a post for review', 'causeway-claude-bridge' ), self::actor() ),
				'rows'    => array_merge(
					self::rows( $post, array() ),
					array( __( 'Preview', 'causeway-claude-bridge' ) => (string) get_preview_post_link( $post ) )
				),
				'link'    => array(
					'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
					'label' => __( 'Review in WP Admin', 'causeway-claude-bridge' ),
				),
				'footer'  => self::footer(),
			)
		);
	}

	/**
	 * Recipients for an event, or none if it's switched off. The current user is always left out.
	 *
	 * @param string $event          Config event key.
	 * @param bool   $include_extra  Whether extra addresses get this event.
	 * @return list<string>
	 */
	private static function recipients( string $event, bool $include_extra ): array {
		$config = ( new ConfigRepository() )->get()['notifications'];
		if ( empty( $config['events'][ $event ] ) ) {
			return array();
		}
		$to = array();
		if ( ! empty( $config['recipients']['publishers'] ) ) {
			$to = Mailer::publisher_emails( get_current_user_id() );
		}
		if ( $include_extra ) {
			$to = array_merge( $to, (array) $config['recipients']['extra'] );
		}
		$me = wp_get_current_user()->user_email;
		return array_values( array_diff( array_unique( $to ), array( $me ) ) );
	}

	/**
	 * Common rows: title, section, who, when.
	 *
	 * @param WP_Post              $post    Post.
	 * @param array<string, mixed> $summary Summary, if any.
	 * @return array<string, string>
	 */
	private static function rows( WP_Post $post, array $summary ): array {
		$sections = new Sections();
		$key      = $sections->key_for_post( $post );
		$section  = null === $key ? $post->post_type : (string) $sections->all()[ $key ]['label'];
		return array(
			__( 'Title', 'causeway-claude-bridge' )   => html_entity_decode( get_the_title( $post ), ENT_QUOTES ),
			__( 'Section', 'causeway-claude-bridge' ) => $section,
			__( 'By', 'causeway-claude-bridge' )      => self::actor(),
			__( 'When', 'causeway-claude-bridge' )    => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		) + ( isset( $summary['action'] ) ? array( __( 'Change', 'causeway-claude-bridge' ) => (string) $summary['action'] ) : array() );
	}

	/**
	 * The current user's name.
	 */
	private static function actor(): string {
		$user = wp_get_current_user();
		return $user->exists() ? $user->display_name : __( 'Someone', 'causeway-claude-bridge' );
	}

	/**
	 * Footer naming the source and the support contact.
	 */
	public static function footer(): string {
		$support = ( new ConfigRepository() )->get()['support'];
		$contact = trim( $support['name'] . ' ' . ( '' === $support['email'] ? '' : '<' . $support['email'] . '>' ) );
		return '' === $contact
			? __( 'Sent by Claude Publishing on this website.', 'causeway-claude-bridge' )
			/* translators: %s: support contact */
			: sprintf( __( 'Sent by Claude Publishing on this website. Questions: %s', 'causeway-claude-bridge' ), $contact );
	}
}
