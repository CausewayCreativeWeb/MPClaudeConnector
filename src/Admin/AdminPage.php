<?php
/**
 * Settings → Claude Publishing (docs/08).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Admin;

use Causeway\ClaudeBridge\Checks\PreFlight;
use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Config\DefaultConfig;
use Causeway\ClaudeBridge\Config\SiteStructure;
use Causeway\ClaudeBridge\Roles\RoleManager;
use Causeway\ClaudeBridge\SetupPack\SetupPackBuilder;
use Causeway\ClaudeBridge\SetupPack\SetupPackDownload;

/**
 * Registers the screen, loads its assets only there, and prints the app shell.
 */
final class AdminPage {

	public const SLUG = 'claude-publishing';

	/**
	 * Hook suffix of the screen.
	 *
	 * @var string
	 */
	private static string $hook = '';

	/**
	 * Hooks the menu and assets.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Adds the menu item. Admins and Publishers (`ccb_view_activity`) see it; Contributors don't.
	 */
	public static function menu(): void {
		$hook       = add_options_page(
			__( 'Claude Publishing', 'causeway-claude-bridge' ),
			__( 'Claude Publishing', 'causeway-claude-bridge' ),
			RoleManager::CAP_VIEW_ACTIVITY,
			self::SLUG,
			array( self::class, 'render' )
		);
		self::$hook = false === $hook ? '' : $hook;
	}

	/**
	 * Tabs the current user can see, in order.
	 *
	 * @return array<string, string> Key => label.
	 */
	public static function tabs(): array {
		$all = array(
			'overview'      => __( 'Overview', 'causeway-claude-bridge' ),
			'sections'      => __( 'Sections', 'causeway-claude-bridge' ),
			'writing'       => __( 'Writing style', 'causeway-claude-bridge' ),
			'media'         => __( 'Images and files', 'causeway-claude-bridge' ),
			'access'        => __( 'Access', 'causeway-claude-bridge' ),
			'notifications' => __( 'Notifications', 'causeway-claude-bridge' ),
			'activity'      => __( 'Activity', 'causeway-claude-bridge' ),
			'setup-pack'    => __( 'Setup pack', 'causeway-claude-bridge' ),
			'advanced'      => __( 'Advanced', 'causeway-claude-bridge' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			return $all;
		}
		return array_intersect_key( $all, array_flip( array( 'overview', 'activity' ) ) );
	}

	/**
	 * Loads the compiled CSS and JS on this screen only.
	 *
	 * @param string $hook Current screen's hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( '' === self::$hook || $hook !== self::$hook ) {
			return;
		}
		$base = plugins_url( 'assets/dist/', CCB_FILE );
		wp_enqueue_style( 'ccb-admin', $base . 'admin.css', array(), CCB_VERSION );
		wp_enqueue_script( 'ccb-admin', $base . 'admin.js', array(), CCB_VERSION, array( 'in_footer' => true ) );
		wp_add_inline_script( 'ccb-admin', 'window.ccbBoot = ' . wp_json_encode( self::boot_data() ) . ';', 'before' );
	}

	/**
	 * Initial data for the Alpine store.
	 *
	 * @return array<string, mixed>
	 */
	public static function boot_data(): array {
		$can_manage = current_user_can( 'manage_options' );
		$repo       = new ConfigRepository();
		$config     = $repo->get();
		$saved_by   = get_userdata( (int) $config['saved_by'] );

		$data = array(
			'rest'       => esc_url_raw( rest_url( RestController::NAMESPACE . '/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'canManage'  => $can_manage,
			'tabs'       => array_keys( self::tabs() ),
			'site'       => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'version'    => (int) $config['version'],
			'savedBy'    => false === $saved_by ? '' : $saved_by->display_name,
			'support'    => $config['support'],
			'pluginVer'  => CCB_VERSION,
			'setupPack'  => array(
				'version'  => PreFlight::latest_skill_version(),
				'download' => current_user_can( RoleManager::CAP_USE ) && ( new SetupPackBuilder() )->available() ? SetupPackDownload::url() : '',
			),
			'addUserUrl' => admin_url( 'user-new.php' ),
			'i18n'       => self::strings(),
		);
		if ( $can_manage ) {
			$data['config']         = $config;
			$data['structure']      = ( new SiteStructure() )->get();
			$data['sectionDefault'] = DefaultConfig::section( '', '' );
			$data['seoPlugin']      = SiteStructure::detect_seo_plugin();
		}
		return $data;
	}

	/**
	 * Strings the script shows, translated here.
	 *
	 * @return array<string, string>
	 */
	public static function strings(): array {
		return array(
			'after'              => __( 'After', 'causeway-claude-bridge' ),
			'apply'              => __( 'Apply', 'causeway-claude-bridge' ),
			'badEmail'           => __( 'That doesn\'t look like an email address.', 'causeway-claude-bridge' ),
			'before'             => __( 'Before', 'causeway-claude-bridge' ),
			'checking'           => __( 'Checking…', 'causeway-claude-bridge' ),
			'checksDone'         => __( 'Checks finished.', 'causeway-claude-bridge' ),
			'close'              => __( 'Close', 'causeway-claude-bridge' ),
			'confirm'            => __( 'Confirm', 'causeway-claude-bridge' ),
			/* translators: 1: user name, 2: version number */
			'conflictBody'       => __( '%1$s saved version %2$s while you were editing. Reload their version and redo your changes, or overwrite theirs with yours.', 'causeway-claude-bridge' ),
			'conflictTitle'      => __( 'Someone else saved changes', 'causeway-claude-bridge' ),
			'copied'             => __( 'Copied.', 'causeway-claude-bridge' ),
			'current'            => __( '(current)', 'causeway-claude-bridge' ),
			'debugOff'           => __( 'Logs requests and responses, with passwords, tokens and post content removed. Switches itself off after 24 hours.', 'causeway-claude-bridge' ),
			/* translators: %s: date and time */
			'debugUntil'         => __( 'On until %s.', 'causeway-claude-bridge' ),
			/* translators: %s: taxonomy name, e.g. Category */
			'defaultTerms'       => __( 'Default %s', 'causeway-claude-bridge' ),
			/* translators: 1: date, 2: date, 3: IP address */
			'deviceMeta'         => __( 'Created %1$s · last used %2$s · last IP %3$s', 'causeway-claude-bridge' ),
			/* translators: %d: number of devices */
			'devicesMany'        => __( '%d devices', 'causeway-claude-bridge' ),
			/* translators: %d: number of devices */
			'devicesOne'         => __( '%d device', 'causeway-claude-bridge' ),
			'discarded'          => __( 'Changes discarded.', 'causeway-claude-bridge' ),
			'done'               => __( 'Done', 'causeway-claude-bridge' ),
			'edit'               => __( 'Edit', 'causeway-claude-bridge' ),
			/* translators: %s: section label */
			'enableSection'      => __( 'Switch on %s', 'causeway-claude-bridge' ),
			/* translators: %d: number of log entries */
			'entries'            => __( '%d entries', 'causeway-claude-bridge' ),
			'error'              => __( 'Error', 'causeway-claude-bridge' ),
			'importTitle'        => __( 'Import settings', 'causeway-claude-bridge' ),
			'installer'          => __( 'the installer', 'causeway-claude-bridge' ),
			/* translators: %d: number of errors */
			'invalid'            => __( '%d settings need fixing before you can save.', 'causeway-claude-bridge' ),
			'loading'            => __( 'Loading…', 'causeway-claude-bridge' ),
			'madeContributor'    => __( 'Now an AI Contributor.', 'causeway-claude-bridge' ),
			'madePublisher'      => __( 'Now an AI Publisher.', 'causeway-claude-bridge' ),
			/* translators: %d: word limit */
			'maxWords'           => __( 'up to %d words', 'causeway-claude-bridge' ),
			'missing'            => __( 'missing', 'causeway-claude-bridge' ),
			/* translators: %s: field name */
			'missingField'       => __( 'Field %s no longer exists.', 'causeway-claude-bridge' ),
			/* translators: %s: term slug */
			'missingTerm'        => __( 'Term "%s" doesn\'t exist on this site, so it was left out.', 'causeway-claude-bridge' ),
			/* translators: 1: term ID, 2: taxonomy */
			'missingTermId'      => __( 'Term #%1$s in %2$s no longer exists.', 'causeway-claude-bridge' ),
			'never'              => __( 'never', 'causeway-claude-bridge' ),
			'newField'           => __( 'New field', 'causeway-claude-bridge' ),
			'noTemplate'         => __( 'No Elementor single template covers this post type, so posts will use the theme\'s template.', 'causeway-claude-bridge' ),
			'none'               => __( 'None', 'causeway-claude-bridge' ),
			'openSectionHint'    => __( 'Open a section to see the rules Claude gets.', 'causeway-claude-bridge' ),
			'overwrite'          => __( 'Overwrite with mine', 'causeway-claude-bridge' ),
			/* translators: %s: version */
			'packVersion'        => __( 'Setup pack version %s', 'causeway-claude-bridge' ),
			/* translators: 1: page, 2: pages */
			'pageOf'             => __( 'Page %1$s of %2$s', 'causeway-claude-bridge' ),
			'postcodeMap'        => __( 'postcode to map', 'causeway-claude-bridge' ),
			/* translators: %d: number of posts */
			'postsMany'          => __( '%d posts', 'causeway-claude-bridge' ),
			/* translators: %d: number of posts */
			'postsOne'           => __( '%d post', 'causeway-claude-bridge' ),
			'presetTitle'        => __( 'Apply preset', 'causeway-claude-bridge' ),
			'refreshed'          => __( 'Site re-read.', 'causeway-claude-bridge' ),
			'rejected'           => __( 'Refused', 'causeway-claude-bridge' ),
			'reloaded'           => __( 'Loaded the latest settings.', 'causeway-claude-bridge' ),
			/* translators: %s: item name */
			'removeItem'         => __( 'Remove %s', 'causeway-claude-bridge' ),
			'removeSectionBody'  => __( 'Claude will stop seeing it once you save. Posts already written aren\'t affected.', 'causeway-claude-bridge' ),
			'removeSectionTitle' => __( 'Remove this section?', 'causeway-claude-bridge' ),
			'requestFailed'      => __( 'Something went wrong. Reload the page and try again.', 'causeway-claude-bridge' ),
			/* translators: %s: taxonomy name */
			'requireTax'         => __( 'Every post needs a %s', 'causeway-claude-bridge' ),
			'reset'              => __( 'Reset', 'causeway-claude-bridge' ),
			'resetBody'          => __( 'Every section and setting goes back to the defaults. The current settings stay in the version history.', 'causeway-claude-bridge' ),
			'resetTitle'         => __( 'Reset all settings?', 'causeway-claude-bridge' ),
			'resetWord'          => __( 'RESET', 'causeway-claude-bridge' ),
			'restore'            => __( 'Restore this version', 'causeway-claude-bridge' ),
			/* translators: %d: version */
			'restored'           => __( 'Version %d restored as a new version.', 'causeway-claude-bridge' ),
			'revoke'             => __( 'Revoke', 'causeway-claude-bridge' ),
			/* translators: 1: device name, 2: user name */
			'revokeBody'         => __( '"%1$s" will stop working for %2$s straight away. They\'ll need a new Application Password on that device.', 'causeway-claude-bridge' ),
			'revokeTitle'        => __( 'Revoke this device?', 'causeway-claude-bridge' ),
			'revoked'            => __( 'Device revoked.', 'causeway-claude-bridge' ),
			/* translators: %s: field label */
			'ruleFor'            => __( 'Rule for %s', 'causeway-claude-bridge' ),
			'runChecks'          => __( 'Run checks now', 'causeway-claude-bridge' ),
			'saveAnyway'         => __( 'Save anyway', 'causeway-claude-bridge' ),
			'saveChanges'        => __( 'Save changes', 'causeway-claude-bridge' ),
			/* translators: %d: version */
			'saved'              => __( 'Saved as version %d.', 'causeway-claude-bridge' ),
			'saving'             => __( 'Saving…', 'causeway-claude-bridge' ),
			'searchTerms'        => __( 'Search terms…', 'causeway-claude-bridge' ),
			'sectionKey'         => __( 'Choose a post type and a short name that isn\'t used yet.', 'causeway-claude-bridge' ),
			'settingSaved'       => __( 'Setting saved.', 'causeway-claude-bridge' ),
			/* translators: %s: section key */
			'skippedSection'     => __( 'Section "%s" was skipped: its post type isn\'t on this site.', 'causeway-claude-bridge' ),
			'state_good'         => __( 'Good', 'causeway-claude-bridge' ),
			'state_neutral'      => __( 'Not known', 'causeway-claude-bridge' ),
			'state_problem'      => __( 'Problem', 'causeway-claude-bridge' ),
			'state_warning'      => __( 'Warning', 'causeway-claude-bridge' ),
			'status_good'        => __( 'Connected', 'causeway-claude-bridge' ),
			'status_problem'     => __( 'Problem', 'causeway-claude-bridge' ),
			'status_warning'     => __( 'Warning', 'causeway-claude-bridge' ),
			'success'            => __( 'Done', 'causeway-claude-bridge' ),
			/* translators: %s: template names */
			'templateCovers'     => __( 'Shown with: %s', 'causeway-claude-bridge' ),
			/* translators: %s: email address */
			'testSent'           => __( 'Test email sent to %s.', 'causeway-claude-bridge' ),
			'theirs'             => __( 'Theirs', 'causeway-claude-bridge' ),
			'tooLarge'           => __( 'That file is larger than 256 KB, so it can\'t be a settings export.', 'causeway-claude-bridge' ),
			/* translators: %s: the word to type */
			'typeToConfirm'      => __( 'Type %s to confirm', 'causeway-claude-bridge' ),
			/* translators: %d: number of tabs */
			'unsavedMany'        => __( 'Unsaved changes in %d sections', 'causeway-claude-bridge' ),
			/* translators: %d: number of tabs */
			'unsavedOne'         => __( 'Unsaved changes in %d section', 'causeway-claude-bridge' ),
			'updating'           => __( 'updating…', 'causeway-claude-bridge' ),
			/* translators: 1: version, 2: user name */
			'versionBy'          => __( 'Settings version %1$s by %2$s', 'causeway-claude-bridge' ),
			/* translators: %d: version */
			'versionTitle'       => __( 'Version %d compared with now', 'causeway-claude-bridge' ),
			'warningsTitle'      => __( 'Check before saving', 'causeway-claude-bridge' ),
			'yours'              => __( 'Yours', 'causeway-claude-bridge' ),
		);
	}

	/**
	 * Prints the app shell.
	 */
	public static function render(): void {
		if ( ! current_user_can( RoleManager::CAP_VIEW_ACTIVITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'causeway-claude-bridge' ), 403 );
		}
		$tabs       = self::tabs();
		$can_manage = current_user_can( 'manage_options' );
		include CCB_DIR . 'templates/admin/shell.php';
	}

	/**
	 * Prints a component partial.
	 *
	 * @param string               $name Component file name without `.php`.
	 * @param array<string, mixed> $args Variables for the partial.
	 */
	public static function component( string $name, array $args = array() ): void {
		if ( ! preg_match( '/^[a-z-]+$/', $name ) ) {
			return;
		}
		$file = CCB_DIR . 'templates/admin/components/' . $name . '.php';
		if ( is_readable( $file ) ) {
			( static function () use ( $file, $args ): void {
				extract( $args, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variables.
				include $file;
			} )();
		}
	}
}
