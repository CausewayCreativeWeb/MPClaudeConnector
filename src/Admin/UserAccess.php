<?php
/**
 * Publishers, Contributors and their Application Passwords (docs/08 "Access", docs/02).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Admin;

use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Roles\RoleManager;
use WP_Application_Passwords;
use WP_Error;
use WP_User;

/**
 * Switching between AI Contributor and AI Publisher, and listing and revoking device passwords.
 * Every change writes an audit row.
 */
final class UserAccess {

	/**
	 * Publishers (administrators first, locked) and Contributors.
	 *
	 * @return array{publishers: list<array<string, mixed>>, contributors: list<array<string, mixed>>}
	 */
	public static function list(): array {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'display_name',
			)
		);
		$pubs   = get_users(
			array(
				'role'    => RoleManager::ROLE_PUBLISHER,
				'orderby' => 'display_name',
			)
		);
		$conts  = get_users(
			array(
				'role'    => RoleManager::ROLE_CONTRIBUTOR,
				'orderby' => 'display_name',
			)
		);

		$publishers = array();
		foreach ( array_merge( $admins, $pubs ) as $user ) {
			$publishers[ $user->ID ] = self::present( $user );
		}
		$contributors = array();
		foreach ( $conts as $user ) {
			if ( ! isset( $publishers[ $user->ID ] ) ) {
				$contributors[] = self::present( $user );
			}
		}
		return array(
			'publishers'   => array_values( $publishers ),
			'contributors' => $contributors,
		);
	}

	/**
	 * One user as the Access tab shows them.
	 *
	 * @param WP_User $user User.
	 * @return array<string, mixed>
	 */
	public static function present( WP_User $user ): array {
		$is_admin = RoleManager::is_administrator( $user );
		return array(
			'id'            => $user->ID,
			'name'          => $user->display_name,
			'email'         => $user->user_email,
			'role'          => $is_admin ? 'administrator' : ( in_array( RoleManager::ROLE_PUBLISHER, (array) $user->roles, true ) ? 'publisher' : 'contributor' ),
			'locked'        => $is_admin,
			'app_passwords' => count( WP_Application_Passwords::get_user_application_passwords( $user->ID ) ),
			'edit_link'     => (string) get_edit_user_link( $user->ID ),
		);
	}

	/**
	 * Switches an AI Contributor to AI Publisher or back.
	 *
	 * @param int    $user_id User.
	 * @param string $to      `publisher` or `contributor`.
	 * @return array<string, mixed>|WP_Error The user after the change.
	 */
	public static function set_role( int $user_id, string $to ): array|WP_Error {
		$user = get_userdata( $user_id );
		if ( false === $user ) {
			return new WP_Error( 'ccb_not_found', __( 'User not found.', 'causeway-claude-bridge' ), array( 'status' => 404 ) );
		}
		if ( RoleManager::is_administrator( $user ) ) {
			return new WP_Error( 'ccb_forbidden', __( 'Administrators are always Publishers.', 'causeway-claude-bridge' ), array( 'status' => 400 ) );
		}
		$roles = array(
			'publisher'   => RoleManager::ROLE_PUBLISHER,
			'contributor' => RoleManager::ROLE_CONTRIBUTOR,
		);
		if ( ! isset( $roles[ $to ] ) ) {
			return new WP_Error( 'ccb_invalid_input', __( 'Unknown role.', 'causeway-claude-bridge' ), array( 'status' => 400 ) );
		}
		$from = $roles[ 'publisher' === $to ? 'contributor' : 'publisher' ];
		if ( in_array( $roles[ $to ], (array) $user->roles, true ) && ! in_array( $from, (array) $user->roles, true ) ) {
			return self::present( $user );
		}
		if ( ! in_array( $from, (array) $user->roles, true ) ) {
			return new WP_Error( 'ccb_forbidden', __( 'Only AI Contributors and AI Publishers can be switched here. Change other roles from Users.', 'causeway-claude-bridge' ), array( 'status' => 400 ) );
		}

		$user->remove_role( $from );
		$user->add_role( $roles[ $to ] );

		ActivityLog::record(
			array(
				'action'      => 'role_change',
				'result'      => 'success',
				'status_from' => $from,
				'status_to'   => $roles[ $to ],
				'message'     => $user->display_name,
			),
			array( 'user_id' => $user->ID )
		);
		clean_user_cache( $user );
		$fresh = get_userdata( $user->ID );
		return self::present( false === $fresh ? $user : $fresh );
	}

	/**
	 * A user's Application Passwords (never the passwords themselves).
	 *
	 * @param int $user_id User.
	 * @return list<array{uuid: string, name: string, created: string, last_used: string, last_ip: string}>|WP_Error
	 */
	public static function app_passwords( int $user_id ): array|WP_Error {
		$user = self::connection_user( $user_id );
		if ( $user instanceof WP_Error ) {
			return $user;
		}
		$list = array();
		foreach ( WP_Application_Passwords::get_user_application_passwords( $user_id ) as $item ) {
			$list[] = array(
				'uuid'      => (string) $item['uuid'],
				'name'      => (string) $item['name'],
				'created'   => empty( $item['created'] ) ? '' : wp_date( 'j M Y H:i', (int) $item['created'] ),
				'last_used' => empty( $item['last_used'] ) ? '' : wp_date( 'j M Y H:i', (int) $item['last_used'] ),
				'last_ip'   => (string) ( $item['last_ip'] ?? '' ),
			);
		}
		return $list;
	}

	/**
	 * Revokes one Application Password.
	 *
	 * @param int    $user_id User.
	 * @param string $uuid    Password UUID.
	 */
	public static function revoke( int $user_id, string $uuid ): bool|WP_Error {
		$user = self::connection_user( $user_id );
		if ( $user instanceof WP_Error ) {
			return $user;
		}
		$item = WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		if ( null === $item ) {
			return new WP_Error( 'ccb_not_found', __( 'That device password no longer exists.', 'causeway-claude-bridge' ), array( 'status' => 404 ) );
		}
		$deleted = WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( $deleted instanceof WP_Error ) {
			return $deleted;
		}
		ActivityLog::record(
			array(
				'action'  => 'app_password_revoke',
				'result'  => 'success',
				'message' => $user->display_name . ': ' . (string) $item['name'],
			),
			array(
				'user_id' => $user_id,
				'uuid'    => $uuid,
			)
		);
		return true;
	}

	/**
	 * A user who may use the connection.
	 *
	 * @param int $user_id User.
	 */
	private static function connection_user( int $user_id ): WP_User|WP_Error {
		$user = get_userdata( $user_id );
		if ( false === $user || ! user_can( $user, RoleManager::CAP_USE ) ) {
			return new WP_Error( 'ccb_not_found', __( 'User not found.', 'causeway-claude-bridge' ), array( 'status' => 404 ) );
		}
		return $user;
	}
}
