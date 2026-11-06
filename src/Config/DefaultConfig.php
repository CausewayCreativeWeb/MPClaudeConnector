<?php
/**
 * Default configuration values (docs/04-configuration.md).
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Config;

/**
 * Builds the default config stored on activation and the defaults for a new section.
 */
final class DefaultConfig {

	public const SCHEMA_VERSION = 1;

	/**
	 * A complete, valid config with no sections enabled.
	 *
	 * @param string $support_email Support contact email. The site admin email is used when empty.
	 * @return array<string, mixed>
	 */
	public static function get( string $support_email = '' ): array {
		if ( '' === $support_email && function_exists( 'get_option' ) ) {
			$support_email = (string) get_option( 'admin_email', '' );
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'version'        => 1,
			'saved_by'       => 0,
			'saved_at_gmt'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'sections'       => array(),
			'writing'        => array(
				'language'            => 'en-GB',
				'headings'            => array( 'h2', 'h3' ),
				'allowed_html'        => array( 'p', 'h2', 'h3', 'ul', 'ol', 'li', 'a', 'strong', 'em', 'blockquote', 'figure', 'figcaption', 'img', 'br' ),
				'strip_inline_styles' => true,
				'link_rules'          => array(
					'external_new_tab'  => false,
					'nofollow_external' => false,
				),
				'house_style'         => '',
				'words_to_avoid'      => array(),
				'sign_off'            => '',
				'seo'                 => array(
					'plugin'          => 'auto',
					'title_max'       => 60,
					'description_max' => 155,
				),
			),
			'media'          => array(
				'image_types'         => array( 'image/jpeg', 'image/png', 'image/webp' ),
				'max_upload_mb'       => 4,
				'max_edge_px'         => 2000,
				'webp_quality'        => 82,
				'strip_metadata'      => true,
				'pdf_max_mb'          => 20,
				'upload_link_minutes' => 15,
			),
			'access'         => array(
				'requests_per_minute'  => 30,
				'live_changes_per_day' => 20,
			),
			'notifications'  => array(
				'recipients' => array(
					'publishers' => true,
					'extra'      => array(),
				),
				'events'     => array(
					'pending_post'   => true,
					'live_change'    => true,
					'removal'        => true,
					'health_failure' => true,
				),
			),
			'support'        => array(
				'name'  => '',
				'email' => $support_email,
				'url'   => '',
			),
			'activity'       => array(
				'retention_months' => 12,
			),
		);
	}

	/**
	 * Defaults for a new section. The admin UI and presets start from this.
	 *
	 * @param string $post_type Post type the section maps to.
	 * @param string $label     Label shown to staff.
	 * @return array<string, mixed>
	 */
	public static function section( string $post_type, string $label ): array {
		return array(
			'enabled'               => false,
			'label'                 => $label,
			'post_type'             => $post_type,
			'notes'                 => '',
			'default_terms'         => array(),
			'required_taxonomies'   => array(),
			'featured_image'        => array(
				'required'  => false,
				'ratio'     => 'any',
				'min_width' => 0,
			),
			'limits'                => array(
				'min_words'         => 0,
				'max_words'         => 0,
				'excerpt_max_chars' => 160,
				'max_images'        => 5,
			),
			'field_rules'           => array(),
			'extra_fields'          => array(),
			'elementor_template_id' => 0,
		);
	}
}
