<?php
/**
 * SEO plugin meta keys (docs/05 "Excerpt, slug and SEO fields").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Content;

use Causeway\ClaudeBridge\Config\SiteStructure;

/**
 * Where each supported SEO plugin keeps a post's SEO title and description.
 * SEOPress is used on all the client's sites; keys still to confirm at the site audit.
 */
final class SeoFields {

	public const KEYS = array(
		'seopress' => array(
			'title'       => '_seopress_titles_title',
			'description' => '_seopress_titles_desc',
		),
		'yoast'    => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
		),
		'rankmath' => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
		),
	);

	/**
	 * The SEO plugin in use: the config's choice, or the detected one when set to `auto`.
	 *
	 * @param array<string, mixed> $config Config.
	 */
	public static function plugin( array $config ): string {
		$chosen = (string) ( $config['writing']['seo']['plugin'] ?? 'auto' );
		return 'auto' === $chosen ? SiteStructure::detect_seo_plugin() : $chosen;
	}

	/**
	 * Meta keys for the plugin in use, or null when there's no SEO plugin.
	 *
	 * @param array<string, mixed> $config Config.
	 * @return array{title: string, description: string}|null
	 */
	public static function keys( array $config ): ?array {
		return self::KEYS[ self::plugin( $config ) ] ?? null;
	}
}
