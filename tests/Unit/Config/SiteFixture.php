<?php
/**
 * A site structure shaped like the MP site, for unit tests.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Config;

use Causeway\ClaudeBridge\Config\DefaultConfig;
use Causeway\ClaudeBridge\Config\Presets;

/**
 * Structure and config fixtures.
 */
final class SiteFixture {

	/**
	 * Site structure as SiteStructure::discover() would return it for the MP site.
	 *
	 * @return array<string, mixed>
	 */
	public static function structure(): array {
		return array(
			'post_types' => array(
				array(
					'name'            => 'post',
					'label'           => 'Posts',
					'supports'        => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
					'show_in_rest'    => true,
					'capability_type' => 'post',
					'taxonomies'      => array( 'category', 'post_tag' ),
				),
				array(
					'name'            => 'resource',
					'label'           => 'Resources',
					'supports'        => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'post-formats' ),
					'show_in_rest'    => true,
					'capability_type' => 'post',
					'taxonomies'      => array( 'resource_category', 'content-type' ),
				),
				array(
					'name'            => 'visits',
					'label'           => 'Visits',
					'supports'        => array( 'title', 'editor', 'thumbnail' ),
					'show_in_rest'    => false,
					'capability_type' => 'post',
					'taxonomies'      => array(),
				),
			),
			'taxonomies' => array(
				'category'          => array(
					'label'        => 'Category',
					'hierarchical' => true,
					'terms'        => array(
						array( 'id' => 5, 'slug' => 'news', 'name' => 'News', 'parent' => 0 ),
						array( 'id' => 6, 'slug' => 'events', 'name' => 'Events', 'parent' => 0 ),
					),
				),
				'post_tag'          => array(
					'label'        => 'Tag',
					'hierarchical' => false,
					'terms'        => array(
						array( 'id' => 11, 'slug' => 'schools', 'name' => 'Schools', 'parent' => 0 ),
					),
				),
				'resource_category' => array(
					'label'        => 'Category',
					'hierarchical' => true,
					'terms'        => array(
						array( 'id' => 7, 'slug' => 'reports', 'name' => 'Reports', 'parent' => 0 ),
						array( 'id' => 8, 'slug' => 'briefings', 'name' => 'Briefings', 'parent' => 0 ),
					),
				),
				'content-type'      => array(
					'label'        => 'Type',
					'hierarchical' => true,
					'terms'        => array(
						array( 'id' => 9, 'slug' => 'pdf', 'name' => 'PDF', 'parent' => 0 ),
						array( 'id' => 10, 'slug' => 'article', 'name' => 'Article', 'parent' => 0 ),
					),
				),
			),
			'acf'        => array(
				'available'           => true,
				'fields_by_post_type' => array(
					'resource' => array(
						array( 'key' => 'field_655493153192f', 'name' => 'downloadable_file', 'label' => 'Downloadable file', 'type' => 'file', 'required' => false, 'group' => 'Resource Details', 'writable' => true, 'instructions' => '' ),
						array( 'key' => 'field_6895f57a6a12e', 'name' => 'online_reader_url', 'label' => 'Online reader URL', 'type' => 'page_link', 'required' => false, 'group' => 'Resource Details', 'writable' => true, 'instructions' => 'Only to be used where an online reader is set up' ),
						array( 'key' => 'field_68bef927b733c', 'name' => 'authors', 'label' => 'Author(s)', 'type' => 'textarea', 'required' => false, 'group' => 'Resource Details', 'writable' => true, 'instructions' => '' ),
					),
				),
			),
			'elementor'  => array(
				'active'           => true,
				'single_templates' => array(
					array( 'id' => 120, 'title' => 'Single News', 'conditions' => array( 'include/singular/post' ) ),
					array( 'id' => 121, 'title' => 'Single Resource', 'conditions' => array( 'include/singular/resource' ) ),
				),
			),
			'seo_plugin' => 'seopress',
		);
	}

	/**
	 * The MP preset applied to the default config against structure().
	 *
	 * @return array<string, mixed>
	 */
	public static function mp_config(): array {
		$preview = ( new Presets() )->preview( 'mp-site', DefaultConfig::get( 'support@example.org' ), self::structure() );
		return $preview['config'];
	}
}
