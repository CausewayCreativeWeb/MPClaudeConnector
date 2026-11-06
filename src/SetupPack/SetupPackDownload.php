<?php
/**
 * Setup pack download from the profile page and the Setup pack tab (docs/10).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\SetupPack;

use Causeway\ClaudeBridge\Admin\Overview;
use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Roles\RoleManager;
use WP_User;

/**
 * `admin-post.php?action=ccb_setup_pack` with a nonce, for users who may use the connection.
 * Every download is audit-logged.
 */
final class SetupPackDownload {

	public const ACTION = 'ccb_setup_pack';

	/**
	 * Hooks the handler and the profile row.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_filter( 'ccb_profile_rows', array( self::class, 'profile_row' ), 10, 2 );
	}

	/**
	 * Download URL for the current user.
	 */
	public static function url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
	}

	/**
	 * Adds the download to the user's own profile.
	 *
	 * @param array<string, string> $rows Rows.
	 * @param WP_User               $user Profile user.
	 * @return array<string, string>
	 */
	public static function profile_row( array $rows, WP_User $user ): array {
		if ( get_current_user_id() !== $user->ID || ! user_can( $user, RoleManager::CAP_USE ) ) {
			return $rows;
		}
		$builder = new SetupPackBuilder();
		if ( ! $builder->available() ) {
			$rows[ __( 'Setup pack', 'causeway-claude-bridge' ) ] = esc_html__( 'The setup pack isn\'t included in this copy of the plugin. Ask your site administrator.', 'causeway-claude-bridge' );
			return $rows;
		}
		$rows[ __( 'Setup pack', 'causeway-claude-bridge' ) ] = sprintf(
			'<a class="button" href="%1$s">%2$s</a><p class="description">%3$s</p>',
			esc_url( self::url() ),
			esc_html__( 'Download setup pack', 'causeway-claude-bridge' ),
			esc_html(
				sprintf(
					/* translators: %s: version */
					__( 'Version %s. Connects Claude Desktop to this site. Contains no passwords: you type your username and an Application Password (below) when you install it.', 'causeway-claude-bridge' ),
					SetupPackBuilder::VERSION
				)
			)
		);
		return $rows;
	}

	/**
	 * Builds and sends the pack.
	 */
	public static function handle(): void {
		check_admin_referer( self::ACTION );
		if ( ! current_user_can( RoleManager::CAP_USE ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to download the setup pack.', 'causeway-claude-bridge' ), 403 );
		}
		$builder = new SetupPackBuilder();
		try {
			$path = $builder->build();
		} catch ( \RuntimeException $e ) {
			ActivityLog::record(
				array(
					'action'     => 'setup_pack_download',
					'result'     => 'error',
					'error_code' => 'ccb_setup_pack_unavailable',
					'message'    => $e->getMessage(),
				)
			);
			wp_die( esc_html__( 'The setup pack couldn\'t be built on this site. Ask your site administrator.', 'causeway-claude-bridge' ), 500 );
		}

		ActivityLog::record(
			array(
				'action'  => 'setup_pack_download',
				'result'  => 'success',
				/* translators: %s: version */
				'message' => sprintf( __( 'Setup pack %s', 'causeway-claude-bridge' ), SetupPackBuilder::VERSION ),
			)
		);
		Overview::mark( 'setup_pack' );

		/**
		 * Lets tests take the built file instead of streaming it.
		 *
		 * @param bool   $handled Whether the download was handled.
		 * @param string $path    Built zip.
		 * @param string $name    File name.
		 */
		if ( apply_filters( 'ccb_setup_pack_send', false, $path, $builder->filename() ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $builder->filename() . '"' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a download.
		wp_delete_file( $path );
		exit;
	}
}
