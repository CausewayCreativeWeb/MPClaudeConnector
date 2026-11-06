<?php
/**
 * Roles and capabilities (docs/02-roles-and-permissions.md).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Roles;

use WP_User;

/**
 * Creates the AI Contributor and AI Publisher roles and gives administrators the Bridge capabilities.
 */
final class RoleManager {

	public const CAP_USE           = 'ccb_use_connection';
	public const CAP_PUBLISH       = 'ccb_publish';
	public const CAP_VIEW_ACTIVITY = 'ccb_view_activity';

	public const ROLE_CONTRIBUTOR = 'ccb_contributor';
	public const ROLE_PUBLISHER   = 'ccb_publisher';

	/**
	 * Capabilities the Bridge roles must never hold, whatever else changes (docs/09).
	 */
	public const FORBIDDEN_CAPS = array(
		'unfiltered_html',
		'manage_categories',
		'manage_links',
		'moderate_comments',
		'manage_options',
		'edit_theme_options',
		'edit_pages',
		'edit_others_pages',
		'edit_published_pages',
		'edit_private_pages',
		'publish_pages',
		'delete_pages',
		'delete_others_pages',
		'delete_published_pages',
		'delete_private_pages',
		'read_private_pages',
	);

	/**
	 * All Bridge capabilities.
	 *
	 * @return list<string>
	 */
	public static function bridge_caps(): array {
		return array( self::CAP_USE, self::CAP_PUBLISH, self::CAP_VIEW_ACTIVITY );
	}

	/**
	 * AI Contributor capabilities: the Editor's post capabilities with publishing removed.
	 *
	 * @return array<string, bool>
	 */
	public static function contributor_caps(): array {
		return array_fill_keys(
			array(
				'read',
				'upload_files',
				'edit_posts',
				'edit_others_posts',
				'edit_published_posts',
				'edit_private_posts',
				'delete_posts',
				'delete_others_posts',
				'delete_published_posts',
				'delete_private_posts',
				'read_private_posts',
				self::CAP_USE,
			),
			true
		);
	}

	/**
	 * AI Publisher capabilities: Contributor plus publishing and read-only activity.
	 *
	 * @return array<string, bool>
	 */
	public static function publisher_caps(): array {
		return array_merge(
			self::contributor_caps(),
			array_fill_keys( array( 'publish_posts', self::CAP_PUBLISH, self::CAP_VIEW_ACTIVITY ), true )
		);
	}

	/**
	 * Creates or updates both roles and grants administrators the Bridge capabilities. Idempotent.
	 */
	public static function sync(): void {
		// Role names are stored untranslated, like WordPress core's; they're translated when displayed.
		self::sync_role( self::ROLE_CONTRIBUTOR, 'AI Contributor', self::contributor_caps() );
		self::sync_role( self::ROLE_PUBLISHER, 'AI Publisher', self::publisher_caps() );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::bridge_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Makes a role hold exactly the given capabilities, creating it if needed.
	 *
	 * @param string              $role_name Role slug.
	 * @param string              $label     Display name.
	 * @param array<string, bool> $caps      Capabilities.
	 */
	private static function sync_role( string $role_name, string $label, array $caps ): void {
		$role = get_role( $role_name );
		if ( ! $role ) {
			add_role( $role_name, $label, $caps );
			return;
		}

		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( ! isset( $caps[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
		foreach ( $caps as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
	}

	/**
	 * Hooks the runtime capability rules.
	 */
	public static function register_hooks(): void {
		add_filter( 'user_has_cap', array( self::class, 'filter_user_caps' ), 10, 4 );
	}

	/**
	 * Gives administrators the Bridge capabilities on every request, and strips forbidden
	 * capabilities from anyone whose only roles are Bridge roles, even if another plugin added them.
	 *
	 * @param array<string, bool> $allcaps All capabilities of the user.
	 * @param string[]            $caps    Required primitive capabilities.
	 * @param array<int, mixed>   $args    Arguments to the capability check.
	 * @param WP_User             $user    The user.
	 * @return array<string, bool>
	 */
	public static function filter_user_caps( array $allcaps, array $caps, array $args, WP_User $user ): array {
		unset( $caps, $args );

		if ( self::is_administrator( $user ) ) {
			foreach ( self::bridge_caps() as $cap ) {
				$allcaps[ $cap ] = true;
			}
			return $allcaps;
		}

		$roles = (array) $user->roles;
		if ( array() !== $roles && array() === array_diff( $roles, array( self::ROLE_CONTRIBUTOR, self::ROLE_PUBLISHER ) ) ) {
			foreach ( self::FORBIDDEN_CAPS as $cap ) {
				unset( $allcaps[ $cap ] );
			}
		}

		return $allcaps;
	}

	/**
	 * Whether the user is an administrator (or a super admin on multisite).
	 *
	 * @param WP_User $user The user.
	 */
	public static function is_administrator( WP_User $user ): bool {
		return in_array( 'administrator', (array) $user->roles, true ) || ( is_multisite() && is_super_admin( $user->ID ) );
	}

	/**
	 * Removes both roles and the Bridge capabilities. Users are moved to WordPress roles first
	 * (Contributor → contributor, Publisher → editor, per docs/06) so nobody is left without a role.
	 */
	public static function remove_all(): void {
		$map = array(
			self::ROLE_CONTRIBUTOR => 'contributor',
			self::ROLE_PUBLISHER   => 'editor',
		);

		foreach ( $map as $from => $to ) {
			$users = get_users(
				array(
					'role'   => $from,
					'fields' => 'all',
				)
			);
			foreach ( $users as $user ) {
				$user->remove_role( $from );
				$user->add_role( $to );
			}
			remove_role( $from );
		}

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::bridge_caps() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}
	}
}
