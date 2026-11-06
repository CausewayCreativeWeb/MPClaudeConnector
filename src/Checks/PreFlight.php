<?php
/**
 * Pre-flight checks (docs/07 "1. Pre-flight").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Checks;

use Causeway\ClaudeBridge\Config\ConfigRepository;
use Causeway\ClaudeBridge\Config\SiteStructure;

/**
 * Checks run at the start of every Claude session and by the daily health check.
 * Each problem has a severity: `error` stops Claude, `warning` is shown and work carries on.
 *
 * Covered here: config validity and staleness, enabled sections, post types, ACF, Elementor
 * template coverage, WebP support and setup pack version. HTTPS and the user's capability are
 * enforced on every call before this runs. Health-check age and endpoint reachability come with M7.
 */
final class PreFlight {

	/**
	 * Option holding the version of the bundled extension and skill (set by the setup pack, M9).
	 */
	public const OPTION_SETUP_PACK_VERSION = 'ccb_setup_pack_version';

	/**
	 * Config.
	 *
	 * @var ConfigRepository
	 */
	private ConfigRepository $config;

	/**
	 * Site discovery.
	 *
	 * @var SiteStructure
	 */
	private SiteStructure $site;

	/**
	 * Sets up the checks.
	 *
	 * @param ConfigRepository|null $config Config repository.
	 * @param SiteStructure|null    $site   Site discovery.
	 */
	public function __construct( ?ConfigRepository $config = null, ?SiteStructure $site = null ) {
		$this->site   = $site ?? new SiteStructure();
		$this->config = $config ?? new ConfigRepository( null, $this->site );
	}

	/**
	 * Latest skill version the site offers.
	 */
	public static function latest_skill_version(): string {
		return (string) get_option( self::OPTION_SETUP_PACK_VERSION, CCB_VERSION );
	}

	/**
	 * Runs the checks.
	 *
	 * @param string|null $skill_version Skill version Claude reported, if any.
	 * @return list<array{code: string, severity: string, message: string, fix: string}>
	 */
	public function run( ?string $skill_version = null ): array {
		$issues    = array();
		$config    = $this->config->get();
		$structure = $this->site->get();
		$result    = $this->config->validate_stored();

		if ( ! $result->is_valid() ) {
			$issues[] = self::issue( 'ccb_config_invalid', 'error', __( 'The Claude Publishing settings are invalid.', 'causeway-claude-bridge' ), __( 'An administrator needs to open Settings → Claude Publishing and fix the highlighted settings.', 'causeway-claude-bridge' ) );
			return $issues;
		}

		$post_types = array_column( $structure['post_types'], 'name' );
		$enabled    = array_filter( $config['sections'], static fn ( array $s ): bool => ! empty( $s['enabled'] ) );

		if ( array() === $enabled ) {
			$issues[] = self::issue( 'no_sections', 'error', __( 'No sections are switched on, so there is nowhere to post.', 'causeway-claude-bridge' ), __( 'An administrator needs to switch on at least one section in Settings → Claude Publishing.', 'causeway-claude-bridge' ) );
		}

		$stale = false;
		foreach ( $enabled as $section ) {
			if ( ! in_array( $section['post_type'], $post_types, true ) ) {
				$issues[] = self::issue(
					'post_type_missing',
					'error',
					/* translators: 1: section label, 2: post type */
					sprintf( __( 'The "%1$s" section uses post type "%2$s", which no longer exists.', 'causeway-claude-bridge' ), $section['label'], $section['post_type'] ),
					__( 'Check that the plugin providing it is active, or change the section in Settings → Claude Publishing.', 'causeway-claude-bridge' )
				);
				continue;
			}
			if ( array() !== $section['field_rules'] && empty( $structure['acf']['available'] ) ) {
				$issues[] = self::issue(
					'acf_inactive',
					'error',
					/* translators: %s: section label */
					sprintf( __( 'The "%s" section needs Advanced Custom Fields, which isn\'t active.', 'causeway-claude-bridge' ), $section['label'] ),
					__( 'An administrator needs to activate ACF.', 'causeway-claude-bridge' )
				);
			}
			if ( ! empty( $structure['elementor']['active'] ) && array() === SiteStructure::templates_for( $structure, $section['post_type'] ) ) {
				$issues[] = self::issue(
					'elementor_template_missing',
					'warning',
					/* translators: %s: section label */
					sprintf( __( 'No Elementor single template covers the "%s" section, so its posts may not display as expected.', 'causeway-claude-bridge' ), $section['label'] ),
					__( 'Check the Theme Builder display conditions.', 'causeway-claude-bridge' )
				);
			}
		}

		foreach ( $result->warnings() as $warning ) {
			if ( 'config_stale' === $warning['code'] && ! str_ends_with( $warning['path'], '/post_type' ) && ! str_ends_with( $warning['path'], '/field_rules' ) ) {
				$stale = true;
			}
		}
		if ( $stale ) {
			$issues[] = self::issue( 'config_stale', 'warning', __( 'Some settings refer to categories or fields that have been deleted. They are being ignored.', 'causeway-claude-bridge' ), __( 'An administrator can tidy them up in Settings → Claude Publishing.', 'causeway-claude-bridge' ) );
		}

		if ( function_exists( 'wp_image_editor_supports' ) && ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			$issues[] = self::issue( 'image_webp_unsupported', 'warning', __( 'The server can\'t create WebP images, so images will be kept as JPEG or PNG.', 'causeway-claude-bridge' ), __( 'Ask the host to enable WebP in Imagick or GD.', 'causeway-claude-bridge' ) );
		}

		if ( null !== $skill_version && '' !== $skill_version && version_compare( $skill_version, self::latest_skill_version(), '<' ) ) {
			$issues[] = self::issue( 'skill_outdated', 'warning', __( 'A newer setup pack is available.', 'causeway-claude-bridge' ), __( 'Download it from your WordPress profile page and install it.', 'causeway-claude-bridge' ) );
		}

		return $issues;
	}

	/**
	 * Whether any issue stops work.
	 *
	 * @param list<array{code: string, severity: string, message: string, fix: string}> $issues Issues.
	 */
	public static function has_errors( array $issues ): bool {
		return in_array( 'error', array_column( $issues, 'severity' ), true );
	}

	/**
	 * One issue.
	 *
	 * @param string $code     Code.
	 * @param string $severity error or warning.
	 * @param string $message  Message.
	 * @param string $fix      Fix.
	 * @return array{code: string, severity: string, message: string, fix: string}
	 */
	private static function issue( string $code, string $severity, string $message, string $fix ): array {
		return array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
			'fix'      => $fix,
		);
	}
}
