<?php
/**
 * Claude Publishing section on the user profile (docs/01 hooks, docs/02 "Roles").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Admin;

use Causeway\ClaudeBridge\Roles\RoleManager;
use WP_User;

/**
 * Administrators can switch an AI Contributor to AI Publisher (and back) from the user's profile.
 * The setup pack download is added here in M9.
 */
final class ProfileFields {

	private const FIELD = 'ccb_publisher';

	/**
	 * Hooks the profile screens.
	 */
	public static function register_hooks(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		// After WordPress has saved the profile, so the form's own Role field doesn't undo the switch.
		add_action( 'profile_update', array( self::class, 'save' ), 20 );
	}

	/**
	 * Whether the Publisher toggle applies to this user, for the current user.
	 *
	 * @param WP_User $user Profile user.
	 */
	private static function toggle_applies( WP_User $user ): bool {
		if ( ! current_user_can( 'manage_options' ) || RoleManager::is_administrator( $user ) ) {
			return false;
		}
		return array() !== array_intersect( (array) $user->roles, array( RoleManager::ROLE_CONTRIBUTOR, RoleManager::ROLE_PUBLISHER ) );
	}

	/**
	 * Prints the section.
	 *
	 * @param WP_User $user Profile user.
	 */
	public static function render( WP_User $user ): void {
		$sections = array();
		/**
		 * Rows added to the Claude Publishing section of the profile (e.g. the setup pack download).
		 *
		 * @param array<string, string> $sections Row label => HTML (already escaped).
		 * @param WP_User               $user     Profile user.
		 */
		$sections = (array) apply_filters( 'ccb_profile_rows', $sections, $user );
		$toggle   = self::toggle_applies( $user );
		if ( ! $toggle && array() === $sections ) {
			return;
		}
		$is_publisher = in_array( RoleManager::ROLE_PUBLISHER, (array) $user->roles, true );
		?>
		<h2><?php esc_html_e( 'Claude Publishing', 'causeway-claude-bridge' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php if ( $toggle ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Publisher', 'causeway-claude-bridge' ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( self::FIELD ); ?>_was" value="<?php echo $is_publisher ? '1' : '0'; ?>">
						<input type="hidden" name="<?php echo esc_attr( self::FIELD ); ?>" value="0">
						<label for="ccb-publisher-toggle">
							<input type="checkbox" id="ccb-publisher-toggle" name="<?php echo esc_attr( self::FIELD ); ?>" value="1" aria-describedby="ccb-publisher-help" <?php checked( $is_publisher ); ?>>
							<?php esc_html_e( 'Can publish, schedule, update live posts and move posts to the Bin through Claude', 'causeway-claude-bridge' ); ?>
						</label>
						<p class="description" id="ccb-publisher-help"><?php esc_html_e( 'Unticked, this person is an AI Contributor: their posts always wait for review.', 'causeway-claude-bridge' ); ?></p>
					</td>
				</tr>
			<?php endif; ?>
			<?php foreach ( $sections as $label => $html ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( (string) $label ); ?></th>
					<td><?php echo wp_kses_post( (string) $html ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	/**
	 * Saves the toggle. Only on the profile form, where WordPress has already checked the
	 * `update-user_{id}` nonce and `edit_user`.
	 *
	 * @param int $user_id Profile user.
	 */
	public static function save( int $user_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WordPress verifies the profile form nonce before these actions fire.
		if ( ! isset( $_POST[ self::FIELD ] ) || ! ( did_action( 'edit_user_profile_update' ) || did_action( 'personal_options_update' ) ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( false === $user || ! self::toggle_applies( $user ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- as above.
		$ticked = '1' === sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD ] ) );
		$was    = isset( $_POST[ self::FIELD . '_was' ] ) && '1' === sanitize_text_field( wp_unslash( (string) $_POST[ self::FIELD . '_was' ] ) );
		// phpcs:enable
		if ( $ticked === $was ) {
			return; // Untouched: leave whatever the Role field set.
		}
		$want = $ticked ? 'publisher' : 'contributor';
		UserAccess::set_role( $user_id, $want );
	}
}
